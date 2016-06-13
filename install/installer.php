<?php
session_start();
require_once("common/DBService.php");
require_once("common/DBResult.php");

class Installer {
	const CAUSE_DEPENDENCY_NOT_FOUND = 20041;
	const CAUSE_ALREADY_EXISTED = 20043;
	const ERR_CIRCULAR_DEPENDENCIES = 20044;
	const ERR_CIRCULAR_WANTS = 20045;
	const ERR_MODULE_DOES_NOT_EXIST = 20046;
	const ERR_INVALID_TEST_RESULT = 20047;

	protected $checklist = [];
	protected $shared_module_info = [];
	protected $messages = [];
	protected $successfully_completed_tests = [];

	function __construct() {
		$this_shared_module_info = &$this->shared_module_info;
		$this->shared_module_info = [
			"setSharedModuleInfo" => function($for_module, $key, $value) use (&$this_shared_module_info) {
				$this_shared_module_info[$for_module][$key] = $value;
			},
			// TODO: This should probably be changed (and set somewhere?!?) but where and how?
			// cf. management//install.php - "Additionally, since user privileges are driven through the web, we will need to set up the first admin account to administer other users.  <br />Please enter either your CORAL Authentication Login ID or your externally authenticated Login ID below."
			"common" => ["default_user" => [
					"username" => "coral",
					"password" => "admin"
				]
			]
		];
		$this->scanForModuleInstallers();
		$this->applyRequired();
	}
	private function getKeyFromUid($test_uid)
	{
		require_once("common/array_column.php");
		$key = array_search($test_uid, array_column($this->checklist, 'uid'));
		if ($key === false)
			throw new OutOfBoundsException("Test '$test_uid' not found in checklist.", $this::ERR_MODULE_DOES_NOT_EXIST);

		return $key;
	}
	public function getCheckListUids()
	{
		$arr = $this->checklist;
		usort($arr, function($a, $b){
		    if (isset($a["required"]) && $a["required"] && !isset($a["alternative"])) {
		        return isset($b["required"]) && $b["required"] && !isset($b["alternative"]) ? 0 : -1;
		    }
			else {
				return isset($b["required"]) && $b["required"] && !isset($b["alternative"]) ? 1 : 0;
			}
		});
		require_once("common/array_column.php");
		return array_column($arr, "uid");
	}
	public function getTitleFromUid($uid)
	{
		return $this->checklist[ $this->getKeyFromUid($uid) ]["translatable_title"];
	}
	public function isRequired($uid)
	{
		$alt = isset($this->checklist[ $this->getKeyFromUid($uid) ]["alternative"]) ? true : false;
		$req = isset($this->checklist[ $this->getKeyFromUid($uid) ]["required"]) ? $this->checklist[ $this->getKeyFromUid($uid) ]["required"] && !$alt : false;
		if (isset($this->shared_module_info["modules_to_use"][$uid]["useModule"]))
		{
			$req |= $this->shared_module_info["modules_to_use"][$uid]["useModule"];
		}
		return $req;
	}
	private function applyRequiredToDependencies($uid)
	{
		$key = $this->getKeyFromUid($uid);
		if (!isset($this->checklist[$key]["required"]) || !$this->checklist[$key]["required"])
			return;

		if (isset($this->checklist[$key]["dependencies_array"]))
		{
			foreach ($this->checklist[$key]["dependencies_array"] as $dep)
			{
				try
				{
					$this->checklist[ $this->getKeyFromUid($dep) ]["required"] = true;
					$this->applyRequiredToDependencies($dep);
				}
				catch (Exception $e)
				{
					if ($e->getCode() == $this::ERR_MODULE_DOES_NOT_EXIST)
					{
						$mod_title = $this->checklist[$key]["translatable_title"];
						$this->messages[] = "<b>Warning:</b> There is a problem with the installer for the '$mod_title' module. Dependency '$dep' not found (ignoring).";
					}
					else
					{
						throw $e;
					}
				}
			}
		}
	}
	private function applyRequired()
	{
		foreach ($this->checklist as $test)
		{
			if (isset($test["required"]) && $test["required"])
			{
				$this->applyRequiredToDependencies($test["uid"]);
			}
		}
	}

	public function register_installation_requirement($installer_object)
	{
		$this->checklist[] = $installer_object;
	}

	private function addModule($path, $module_name, $core_module = false)
	{
		require $path;
		$function_name = "register_${module_name}_requirement";
		if (is_callable($function_name))
		{
			$installer_object = call_user_func($function_name);
			$this->register_installation_requirement($installer_object);
			if (!$core_module)
			{
				$mod = [
					"directory" => $module_name,
					"uid" => $installer_object["uid"],
					"title" => $installer_object["translatable_title"],
					"required" => isset($installer_object["required"]) ? $installer_object["required"] : false
				];
				if (isset($installer_object["alternative"]))
				{
					$mod["alternative"] = $installer_object["alternative"];
				}
				if (isset($installer_object["dependencies_array"]))
				{
					$mod["dependencies_array"] = $installer_object["dependencies_array"];
				}
				$this->shared_module_info["module_list"][] = $mod;
			}
			if (isset($installer_object["sharedInfo"]))
			{
				$this->shared_module_info[ $installer_object["uid"] ] = $installer_object["sharedInfo"];
			}
		}
		else
		{
			$this->messages[] = "<b>Warning:</b> There is a problem with the installer for the '$module_name' module (ignoring).";
		}
	}
	private function scanForModuleInstallers()
	{
		// Core Requirements
		$core_requirements_path = "install/requirements/";
		$core_requirements = scandir($core_requirements_path);
		foreach ($core_requirements as $req_module)
		{
			if (trim($req_module, ".") !== "")
			{
				$module_name = basename($req_module, ".php");
				$path = $core_requirements_path . $req_module;
				$this->addModule($path, $module_name, true);
			}
		}

		// Module Installers
		$MODULE_ROOT = ".";
		$module_directories = scandir($MODULE_ROOT);
		foreach ($module_directories as $dir)
		{
			if (is_dir("$MODULE_ROOT/$dir"))
			{
				$installation_root_file = "$MODULE_ROOT/$dir/install/$dir.php";
				if (file_exists($installation_root_file))
				{
					$this->addModule($installation_root_file, $dir);
				}
			}
		}
	}

	// TODO: handle choose module rejecting some and
	// TODO: implement bubbling dependencies
	public function runTestForResult($test_uid, $required_for = [])
	{
		$key = $this->getKeyFromUid($test_uid);
		if ($key === false)
			throw new OutOfBoundsException("Test '{$this->getTitleFromUid($test_uid)}' not found in checklist.", $this::ERR_MODULE_DOES_NOT_EXIST);

		if (isset($this->checklist[$key]["result"]))
		{
			$return = new stdClass();
			$return->skipped = true;
			$return->cause = $this::CAUSE_ALREADY_EXISTED;
			return $return;
		}

		foreach ($this->getDependenciesAndRequiredWants($test_uid) as $dependency) {
			$dep_key = array_search($dependency, array_column($this->checklist, 'uid'));
			if ($dep_key === false)
			{
				$return = new stdClass();
				$return->skipped = false;
				$return->cause = $this::CAUSE_DEPENDENCY_NOT_FOUND;
				$return->missing_dependency = $dependency;
				return $return;
			}

			if (!isset($this->checklist[$dep_key]["result"]))
			{
				if (in_array($dependency, $required_for))
				{
					$required_array = var_export($required_for, true);
					throw new RuntimeException("Error: Circular dependencies ('$test_uid' in $required_array)", $this::ERR_CIRCULAR_DEPENDENCIES);
				}
				$required_for[] = $dependency;
				$result = $this->runTestForResult($dependency, $required_for);
				// If one of the requirements fails, we need its result to be yielded
				if (!$result->success)
					return $result;
			}
		}

		$result = call_user_func( $this->checklist[$key]["installer"], $this->shared_module_info );
		if ($result === null)
			throw new UnexpectedValueException("The install script for '{$this->getTitleFromUid($test_uid)}' has returned a null result (which is not allowed).", $this::ERR_INVALID_TEST_RESULT);

		$this->checklist[$key]["result"] = $result;
		if ($result->success)
			$this->successfully_completed_tests[] = $test_uid;

		return $result;
	}
	private function getDependenciesAndRequiredWants($uid)
	{
		$key = $this->getKeyFromUid($uid);
		$dependencies_array = isset($this->checklist[$key]["dependencies_array"]) ? $this->checklist[$key]["dependencies_array"] : [];
		$wants_array = [];
		if (isset($this->checklist[$key]["wants"]) && is_array($this->checklist[$key]["wants"]))
		{
			foreach ($this->checklist[$key]["wants"] as $wuid) {
				$wkey = $this->getKeyFromUid($wuid);
				if ($this->checklist[$wkey]["required"])
					$wants_array[] = $wuid;
			}
		}
		return array_merge($dependencies_array, $wants_array);
	}

	public function getMessages()
	{
		$messages = $this->messages;
		$this->messages = [];
		return $messages;
	}
	public function getSuccessfullyCompletedTests()
	{
		$uids = [];
		foreach ($this->successfully_completed_tests as $uid) {
			if (!isset($this->checklist[ $this->getKeyFromUid($uid) ]["hide_from_completion_list"]) || !$this->checklist[ $this->getKeyFromUid($uid) ]["hide_from_completion_list"])
				$uids[] = $uid;
		}
		return $uids;
	}
	public function getSuccessfullyCompletedTestTitles()
	{
		$titles = [];
		foreach ($this->getSuccessfullyCompletedTests() as $uid) {
			$titles[] = $this->getTitleFromUid($uid);
		}
		return $titles;
	}
	public function getApproxiamateCompletion()
	{
		return count($this->successfully_completed_tests) / (float) count($this->checklist);
	}

	public function successful_install()
	{
		$return = new stdClass();
		$return->title = _("Installation Complete");
		$return->body = _("Congratulations. Installation has been successful.");
		$return->redirect_home = true;
		return $return;
	}
}
