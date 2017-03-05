<?php

/**
 * Class representing a database object.
 */
abstract class DBObject {
	/** Known database fields for this object. */
	protected static $_fields = [];
	/** Key field name for this object. */
	protected static $_key = 'id';
	/** Table name for this object. */
	protected static $_table = NULL;

	/** Known database values for this object. */
	private $data = [];
	/** Has any of the data been changed since it was last saved/loaded. */
	private $changed = FALSE;
	/** last error for this object. */
	private $lastError = NULL;

	/**
	 * Set a data value.
	 *
	 * @param $key Data key (field name)
	 * @param $value Data value.
	 */
	protected function setData($key, $value) {
		if (static::isField($key)) {
			$this->changed = !$this->hasData($key) || ($this->data[$key] != $value);
			$this->data[$key] = $value;
		}
	}

	/**
	 * Get a data value.
	 *
	 * @param $key Data key (field name)
	 * @return Value for $key (Or the default value if not-known).
	 */
	protected function getData($key) {
		return static::isField($key) ? ($this->hasData($key) ? $this->data[$key] : static::$_fields[$key]) : NULL;
	}

	/**
	 * Do we have a known value for the given key?
	 *
	 * @param $key Data key (field name)
	 * @return True if we have a specific (non-default) value for the key.
	 */
	protected function hasData($key) {
		return array_key_exists($key, $this->data);
	}

	/**
	 * Is this a known field?
	 *
	 * @param $key Data key (field name)
	 * @return True if this is a known field.
	 */
	protected static function isField($key) {
		return array_key_exists($key, static::$_fields);
	}

	/**
	 * Is this object known in the database yet?
	 * (This is a check to see if we have a known value for the key field)
	 *
	 * @return True if this object is known in the database.
	 */
	public function isKnown() {
		return $this->hasData(static::$_key);
	}

	/**
	 * Get the value of the primary key.
	 *
	 * @return The value of the primary key.
	 */
	public function getKeyValue() {
		return $this->getData(static::$_key);
	}

	/**
	 * Load an object from the database.
	 *
	 * @param $db Database object to load from.
	 * @param $key Primary key value to look for.
	 * @return FALSE if no object exists, else the object.
	 */
	public static function load($db, $key) {
		$result = static::find($db, [static::$_key => $key]);
		if ($result) {
			return $result[0];
		} else {
			return FALSE;
		}
	}

	/**
	 * Set the data fields as per the given array.
	 * Fields that are not known will be ignored.
	 *
	 * @param $data Array of data to set.
	 */
	private function setFromArray($data) {
		foreach ($data as $key => $value) {
			$this->setData($key, $value);
		}
	}

	/**
	 * Find an object from the database.
	 *
	 * @param $db Database object to load from.
	 * @param $fields Fields to look for (Array of field => value)
	 * @param $comparators (Optional Array) Comparators to use for fields.
	 * @return FALSE if we were able to find objects, else an array of objects.
	 */
	public static function find($db, $fields, $comparators = []) {
		if (!is_array($fields)) { return FALSE; }

		$keys = [];
		foreach (array_keys(static::$_fields) as $key) {
			$keys[] = '`' . $key . '`';
		}

		$where = [];
		$params = [];
		foreach ($fields as $key => $value) {
			$comparator = isset($comparators[$key]) ? $comparators[$key] : '=';

			$where[] = sprintf('`%s` %s :%s', $key, $comparator, $key);
			$params[':' . $key] = $value;
		}

		$query = sprintf('SELECT %s FROM %s WHERE %s', implode(',', $keys), static::$_table, implode('AND', $where));
		$statement = $db->getPDO()->prepare($query);
		$result = $statement->execute($params);
		if ($result) {
			$return = [];

			$rows = $statement->fetchAll(PDO::FETCH_ASSOC);
			foreach ($rows as $row) {
				$u = new User();
				$u->setFromArray($row);
				$u->postLoad();
				$u->changed = false;

				$return[] = $u;
			}

			return $return;
		} else {
			$this->lastError = $statement->errorInfo();
		}

		return FALSE;
	}

	/**
	 * Save this object to the database.
	 * This will attempt an INSERT if isKnown() is false, else an UPDATE.
	 *
	 * @param $db Database object to save to.
	 * @return TRUE if we saved successfully, else false.
	 */
	public function save($db) {
		$this->presave();
		if (!$this->changed) { return TRUE; }

		$keys = [];
		$placeholders = [];
		$sets = [];
		$params = [];

		foreach (array_keys(static::$_fields) as $key) {
			if ($key == static::$_key) { continue; }

			$keys[] = '`' . $key . '`';
			$placeholders[] = ':' . $key;
			$sets[] = '`' . $key . '` = :' . $key;

			$params[':' . $key] = $this->getData($key);
		}

		if ($this->isKnown()) {
			$keyKey = '`' . static::$_key . '`';
			$keyPlaceholder = ':' . static::$_key;
			$params[$keyPlaceholder] = $this->getData(static::$_key);

			$query = sprintf('UPDATE `%s` SET %s WHERE %s = %s', static::$_table, implode(', ', $sets), $keyKey, $keyPlaceholder);
		} else {
			$query = sprintf('INSERT INTO `%s` (%s) VALUES (%s)', static::$_table, implode(', ', $keys), implode(', ', $placeholders));
		}

		$statement = $db->getPDO()->prepare($query);
		$result = $statement->execute($params);
		if ($result) {
			if (!$this->isKnown()) {
				$this->setData(static::$_key, $db->getPDO()->lastInsertId());
			}
			$this->postSave($result);
			$this->changed = false;
			return TRUE;
		} else {
			$this->lastError = $statement->errorInfo();
			$this->postSave($result);
			return FALSE;
		}

		return FALSE;
	}

	/**
	 * Delete this object from the database.
	 *
	 * @param $db Database object to save to.
	 * @return TRUE if we were deleted, else false.
	 */
	public function delete($db) {
		if (!$this->isKnown()) { return FALSE; }
		$query = sprintf('DELETE FROM %s WHERE `%s` = :key', static::$_table, static::$_key);
		$statement = $db->getPDO()->prepare($query);
		$params[':key'] = $this->getData(static::$_key);
		$result = $statement->execute($params);
		if (!$result) {
			$this->lastError = $statement->errorInfo();
		}

		return $result;
	}

	/**
	 * Get the last error we encountered with the database.
	 *
	 * @return last error.
	 */
	public function getLastError() {
		return $this->lastError;
	}

	/** Hook for after data has been loaded into the object. */
	public function postLoad() { }

	/** Hook for before the object is saved to the database. */
	public function preSave() { }

	/** Hook for after the object is saved to the database. */
	public function postSave($result) { }
}
