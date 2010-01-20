<?php
/**
 * Acl Extras Shell.
 *
 * Enhances the existing Acl Shell with a few handy functions
 *
 * Copyright 2008, Mark Story.
 * 694B The Queensway
 * toronto, ontario M8Y 1K9
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright Copyright 2008-2009, Mark Story.
 * @link http://mark-story.com
 * @version 0.5.1
 * @author Mark Story <mark@mark-story.com>
 * @license http://www.opensource.org/licenses/mit-license.php The MIT License
 */

App::import('Component', 'Acl');
App::import('Model', 'DbAcl');

/**
 * Shell for ACO extras
 *
 * @package		acl_extras
 * @subpackage	acl_extras.vendors.shells
 */
class AclExtrasShell extends Shell {
/**
 * Contains instance of AclComponent
 *
 * @var object
 * @access public
 */
	var $Acl;

/**
 * Contains arguments parsed from the command line.
 *
 * @var array
 * @access public
 */
	var $args;

/**
 * Contains database source to use
 *
 * @var string
 * @access public
 */
	var $dataSource = 'default';

/**
 * Root node name.
 *
 * @var string
 **/
	var $rootNode = 'controllers';

/**
 * Internal Clean Actions switch
 *
 * @var boolean
 **/
	var $_clean = false;

/**
 * Start up And load Acl Component / Aco model
 *
 * @return void
 **/
	function startup() {
		$this->Acl =& new AclComponent();
		$controller = null;
		$this->Acl->startup($controller);
		$this->Aco =& $this->Acl->Aco;
	}

/**
 * Override main() for help message hook
 *
 * @access public
 */
	function main() {
		$out  = __("Available ACO sync commands:", true) . "\n";
		$out .= "\t - aco_update\n";
		$out .= "\t - aco_sync\n";
		$out .= "\t - recover \$type\n";
		$out .= "\t - verify \$type\n";
		$out .= "\t - help\n\n";
		$out .= __("For help, run the 'help' command.  For help on a specific command, run 'help <command>'", true);
		$this->out($out);
	}

/**
 * Updates the Aco Tree with new controller actions.
 *
 * @return void
 **/
	function aco_update() {
		$root = $this->_checkNode($this->rootNode, $this->rootNode, null);
		App::import('Core', array('Controller'));

		$Controllers = $this->getControllerList();
		$appIndex = array_search('App', $Controllers);
		if ($appIndex !== false) {
			unset($Controllers[$appIndex]);
		}
		$Plugins = $this->getPluginControllerNames();
		$Controllers = array_merge($Controllers, $Plugins);

		foreach ($Controllers as $ctrlName) {
			App::import('Controller', str_replace('/','.',$ctrlName));
			// Add plugins node first
			if ( ($pluginInfos = $this->_isPlugin($ctrlName)) !== false){
				$pluginName = $pluginInfos[0];
			    $pluginNode = $this->_checkPluginNode($this->rootNode.'/'.$pluginName,$pluginName,$root['Aco']['id']);
            }
			// Add controllers node
			$controllerNode = $this->_checkNode($this->rootNode . '/' . $ctrlName, $ctrlName, $root['Aco']['id']);
			// Add methods node
			$this->_checkMethods($ctrlName, $controllerNode, $this->_clean);
		}
		if ($this->_clean) {
			$this->Aco->id = $root['Aco']['id'];
			$controllerNodes = $this->Aco->children(null, true);
			$ctrlFlip = array();
			foreach ($Controllers as $Controller) {
				if ( ($pluginInfos = $this->_isPlugin($Controller)) !== false){
					$pluginName = $pluginInfos[0];
					$pluginControllerName = $pluginInfos[1];
			        $ctrlFlip[$pluginName][$pluginControllerName] = 'plugin';
			    } else {
			        $ctrlFlip[$Controller] = 'controller';
			    }
			}
			foreach ($controllerNodes as $ctrlNode) {
				if (!isset($ctrlFlip[$ctrlNode['Aco']['alias']])) {
					$this->Aco->id = $ctrlNode['Aco']['id'];
					if ($this->Aco->delete()) {
						$this->out(sprintf(__('Deleted %s and all children', true), $this->rootNode . '/' . $ctrlNode['Aco']['alias']));
					}
				} else if (is_array($ctrlFlip[$ctrlNode['Aco']['alias']])) {
				    $this->Aco->id = $ctrlNode['Aco']['id'];
				    $pluginNodes = $this->Aco->children(null, true);
				    foreach ($pluginNodes as $pluginNode) {
				        if (!isset($ctrlFlip[$ctrlNode['Aco']['alias']][$pluginNode['Aco']['alias']])) {
				            $this->Aco->id = $pluginNode['Aco']['id'];
				            if ($this->Aco->delete()) {
				                $this->out(sprintf(__('Deleted %s and all children', true), $this->rootNode . '/' . $ctrlNode['Aco']['alias']. '/' . $pluginNode['Aco']['alias']));
				            }
				        }
				    }
				}
			}
		}
		$this->out(__('Aco Update Complete', true));
		return true;
	}

/**
 * Get a list of controllers in the app. Wraps App::objects() for testing
 *
 * @return array
 **/
	function getControllerList() {
		return App::objects('controller', null, false);
	}

/**
 * Get a list of plugins controllers
 *
 * @return array
 **/

	function getPluginControllerNames() {
	    $result = array();
	    $plugins = App::objects('plugin',null,false);
		foreach ($plugins as $plugin) {
		    $path = App::pluginPath($plugin) . 'controllers';
			$controllers = App::objects('controller',$path,false);
			foreach ($controllers as $controller) {
				if ($controller == $plugin.'App') {
					continue;
				}
				$result[] = $plugin . '/' . $controller;
			}
		}
		return $result;
	}

/**
 * Sync the ACO table
 *
 * @return void
 **/
	function aco_sync() {
		$this->_clean = true;
		$this->aco_update();
	}

/**
 * Check a node for existance, create it if it doesn't exist.
 *
 * @param string $path
 * @param string $alias
 * @param int $parentId
 * @return array Aco Node array
 */
	function _checkNode($path, $alias, $parentId = null) {
		$node = $this->Aco->node($path);
		if (!$node) {
			if ( ($pluginInfos = $this->_isPlugin($path)) !== false){
				$pluginName = $pluginInfos[0];
				$pluginControllerName = $pluginInfos[1];
                $pluginNode = $this->Aco->node($this->rootNode.'/' . $pluginName);
                $this->Aco->create(array('parent_id' => $pluginNode['0']['Aco']['id'], 'model' => null, 'alias' => $pluginControllerName));
                $node = $this->Aco->save();
                $node['Aco']['id'] = $this->Aco->id;
                $this->out(sprintf(__('Created Aco node for Plugin Controller %s ', true),$pluginName . '/'. $pluginControllerName ));
		    } else {
    			$this->Aco->create(array('parent_id' => $parentId, 'model' => null, 'alias' => $alias));
    			$node = $this->Aco->save();
    			$node['Aco']['id'] = $this->Aco->id;
    			$this->out(sprintf(__('Created Aco node for Controller %s', true), $path));
		    }
		} else {
			$node = $node[0];
		}
		return $node;
	}

	function _checkPluginNode($path, $alias, $parentId = null) {
		$pluginNode = $this->Aco->node($path);
		if (!$pluginNode) {
			$this->Aco->create(array('parent_id' => $parentId, 'model' => null, 'alias' => $alias));
			$pluginNode = $this->Aco->save();
			$pluginNode['Aco']['id'] = $this->Aco->id;
			$this->out(sprintf(__('Created Aco node for Plugin %s', true), $path));
		}
		return $pluginNode;
	}

	function _checkMethodNode($path, $alias, $parentId = null) {
		$node = $this->Aco->node($path);
		if (!$node) {
			$this->Aco->create(array('parent_id' => $parentId, 'model' => null, 'alias' => $alias));
			$node = $this->Aco->save();
			$node['Aco']['id'] = $this->Aco->id;
			$this->out(sprintf(__('Created Aco node for Method %s', true), $path));
		} else {
			$node = $node[0];
		}
		return $node;
	}

/**
 * Check and Add/delete controller Methods
 *
 * @param string $controller
 * @param array $node
 * @param bool $cleanup
 * @return void
 */
	function _checkMethods($ctrlName, $node, $cleanup = false) {
	    $controller = (($pluginInfos = $this->_isPlugin($ctrlName)) !== false) ? $pluginInfos[1] : $ctrlName;
		$className = $controller . 'Controller';
		$baseMethods = get_class_methods('Controller');
		$actions = get_class_methods($className);
		$methods = array_diff($actions, $baseMethods);
		foreach ($methods as $action) {
			if (strpos($action, '_', 0) === 0) {
				continue;
			}
			$this->_checkMethodNode($this->rootNode . '/' . $ctrlName . '/' . $action, $action, $node['Aco']['id']);
		}
		if ($cleanup) {
			$actionNodes = $this->Aco->children($node['Aco']['id']);
			$methodFlip = array_flip($methods);
			foreach ($actionNodes as $action) {
				if (!isset($methodFlip[$action['Aco']['alias']])) {
					$this->Aco->id = $action['Aco']['id'];
					if ($this->Aco->delete()) {
						$path = $this->rootNode . '/' . $ctrlName . '/' . $action['Aco']['alias'];
						$this->out(sprintf(__('Deleted Aco node %s', true), $path));
					}
				}
			}
		}
		return true;
	}


/**
 * Show help screen.
 *
 * @access public
 */
	function help() {
		$head  = __("Usage: cake acl_extras <command>", true) . "\n";
		$head .= "-----------------------------------------------\n";
		$head .= __("Commands:", true) . "\n\n";

		$commands = array(
			'update' => "\tcake acl_extras aco_update\n" .
						"\t\t" . __("Add new ACOs for new controllers and actions", true) . "\n" .
						"\t\t" . __("Create new ACO's for controllers and their actions. Does not remove any nodes from ACO table", true),

			'sync' =>	"\tcake acl_extras aco_sync\n" .
						"\t\tPerform a full sync on the ACO table.\n" .
						"\t\t" . __("Creates new ACO's for missing controllers and actions. Removes orphaned entries in the ACO table.", true) . "\n",

			'verify' => "\tcake acl_extras verify \$type\n" .
						"\t\t" . __('Verify the tree structure of either your Aco or Aro Trees', true),

			'recover' => "\tcake acl_extras recover \$type\n" .
						 "\t\t" . __('Recover a corrupted Tree', true),

			'help' => 	"\thelp [<command>]\n" .
						"\t\t" . __("Displays this help message, or a message on a specific command.", true) . "\n"
		);

		$this->out($head);
		if (!isset($this->args[0])) {
			foreach ($commands as $cmd) {
				$this->out("{$cmd}\n\n");
			}
		} elseif (isset($commands[low($this->args[0])])) {
			$this->out($commands[low($this->args[0])] . "\n");
		} else {
			$this->out(sprintf(__("Command '%s' not found", true), $this->args[0]));
		}
	}
/**
 * Verify a Acl Tree
 *
 * @param string $type The type of Acl Node to verify
 * @access public
 * @return void
 */
	function verify() {
		if (empty($this->args[0])) {
			$this->err(__('Missing Type', true));
			$this->_stop();
		}
		$type = Inflector::camelize($this->args[0]);
		$return = $this->Acl->{$type}->verify();
		if ($return === true) {
			$this->out(__('Tree is valid and strong', true));
		} else {
			$this->out(print_r($return, true));
		}
	}
/**
 * Recover an Acl Tree
 *
 * @param string $type The Type of Acl Node to recover
 * @access public
 * @return void
 */
	function recover() {
		if (empty($this->args[0])) {
			$this->err(__('Missing Type', true));
			$this->_stop();
		}
		$type = Inflector::camelize($this->args[0]);
		$return = $this->Acl->{$type}->recover();
		if ($return === true) {
			$this->out(__('Tree has been recovered, or tree did not need recovery.', true));
		} else {
			$this->out(__('Tree recovery failed.', true));
		}
	}

    function _isPlugin($ctrlName = null) {
		if (strpos($ctrlName, $this->rootNode.'/') === 0) {
			$ctrlName = substr($ctrlName,strlen($this->rootNode.'/'));
		}
        $arr = String::tokenize($ctrlName, '/');
        if (count($arr) == 2) {
            return $arr;
        } else {
            return false;
        }
    }
}
?>