<?php
/**
* Class for reading/writing to the list of User objects in the database.
*/
class UserDirectory extends DBDirectory {
	/**
	* LDAP connection object
	*/
	private $ldap;
	/**
	* Avoid making multiple LDAP lookups on the same person by caching their details here
	*/
	private $cache_uid;

	public function __construct() {
		parent::__construct();
		global $ldap;
		$this->ldap = $ldap;
		$this->cache_uid = array();
	}

	/**
	* Create the new user in the database.
	* @param User $user object to add
	*/
	public function add_user(User $user) {
		$user_id = $user->uid;
		$user_name = $user->name;
		$user_active = $user->active;
		$user_admin = $user->admin;
		$user_email = $user->email;
		try {
			$stmt = $this->database->prepare("INSERT INTO entity SET type = 'user'");
			$stmt->execute();
			$user->entity_id = $stmt->insert_id;
			$stmt = $this->database->prepare("INSERT INTO user SET entity_id = ?, uid = ?, name = ?, email = ?, active = ?, admin = ?, auth_realm = ?");
			$stmt->bind_param('dsssdds', $user->entity_id, $user_id, $user_name, $user_email, $user_active, $user_admin, $user->auth_realm);
			$stmt->execute();
			$stmt->close();
			$user->log(array('action' => 'User add'));	
		} catch(mysqli_sql_exception $e) {
			if($e->getCode() == 1062) {
				// Duplicate entry
				throw new UserAlreadyExistsException("User {$user->uid} already exists");
			} else {
				throw $e;
			}
		}	
	}

	/**
	* Get a user from the database by its entity ID.
	* @param int $entity_id of user
	* @return User with specified entity ID
	* @throws UserNotFoundException if no user with that entity ID exists
	*/
	public function get_user_by_id($id) {
		$stmt = $this->database->prepare("SELECT * FROM user WHERE entity_id = ?");
		$stmt->bind_param('d', $id);
		$stmt->execute();
		$result = $stmt->get_result();
		if($row = $result->fetch_assoc()) {
			$user = new User($row['entity_id'], $row);
		} else {
			throw new UserNotFoundException('User does not exist.');
		}
		$stmt->close();
		return $user;
	}

	/**
	* Get a user from the database by its uid. If it does not exist in the database, retrieve it
	* from LDAP and store in the database.
	* @param string $uid of user
	* @return User with specified entity uid
	* @throws UserNotFoundException if no user with that uid exists
	*/
	public function get_user_by_uid($uid) {
		global $config, $group_dir, $active_user;
		$ldap_enabled = $config['ldap']['enabled'];
		$group_sync_enabled = $config['ldap']['full_group_sync'];
		try {
			$user = $this->_get_user_by_uid($uid);
		} catch(UserNotFoundException $e) {
			if ($ldap_enabled == 1) {
				$active_user = $this->_get_user_by_uid('keys-sync');
				$user = new User;
				$user->uid = $uid;
				$this->cache_uid[$uid] = $user;
				$user->auth_realm = 'LDAP';

				$user->get_details_from_ldap();
				$ldap_groups = array_map(function($group) {
					return $group["cn"];
				}, $user->ldapgroups);
				$this->add_user($user);

				if($group_sync_enabled == 1) {
					foreach($ldap_groups as $group) {
						try {
							$grp = $group_dir->get_group_by_name($group);
						} catch(GroupNotFoundException $e) {
							$grp = new Group;
							$grp->name = $group;
							$grp->system = 1;
							$group_dir->add_group($grp);
						}
						$grp->add_member($user);
					}
				}
			} else {
				throw new UserNotFoundException('User does not exist.');
			}
		}
		return $user;
	}

	private function _get_user_by_uid($uid) {
		if(isset($this->cache_uid[$uid])) {
			return $this->cache_uid[$uid];
		}
		$stmt = $this->database->prepare("SELECT * FROM user WHERE uid = ?");
		$stmt->bind_param('s', $uid);
		$stmt->execute();
		$result = $stmt->get_result();
		if($row = $result->fetch_assoc()) {
			$user = new User($row['entity_id'], $row);
			$this->cache_uid[$uid] = $user;
		} else {
			throw new UserNotFoundException('User does not exist.');
		}
		$stmt->close();
		return $user;
	}

	/**
	* List all users in the database.
	* @param array $include list of extra data to include in response - currently unused
	* @param array $filter list of field/value pairs to filter results on
	* @return array of User objects
	*/
	public function list_users($include = array(), $filter = array()) {
		// WARNING: The search query is not parameterized - be sure to properly escape all input
		$fields = array("user.*");
		$joins = array();
		$where = array();
		foreach($filter as $field => $value) {
			if($value) {
				switch($field) {
				case 'uid':
					$where[] = "uid = '".$this->database->escape_string($value)."'";
					break;
				case 'name':
					$where[] = "name = '".$this->database->escape_string($value)."'";
					break;
				case 'admins_servers':
					$joins[] = "INNER JOIN server_admin ON server_admin.entity_id = user.entity_id";
					$joins[] = "INNER JOIN server ON server.id = server_admin.server_id AND server.key_management <> 'decommissioned'";
					break;
				}
			}
		}
		$stmt = $this->database->prepare("
			SELECT ".implode(", ", $fields)."
			FROM user ".implode(" ", $joins)."
			".(count($where) == 0 ? "" : "WHERE (".implode(") AND (", $where).")")."
			GROUP BY user.entity_id
			ORDER BY user.uid
		");
		$stmt->execute();
		$result = $stmt->get_result();
		$users = array();
		while($row = $result->fetch_assoc()) {
			$users[] = new User($row['entity_id'], $row);
		}
		return $users;
	}
}

class UserNotFoundException extends Exception {}
class UserAlreadyExistsException extends Exception {}
