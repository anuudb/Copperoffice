<?php
namespace go\core\orm;

use Exception;
use go\core\db\Criteria;
use go\core\util\DateTime;

/**
 * Filters
 * 
 * Holds all filters for an entity
 */
class Filters {
	
	private $filters = [];

	
	/**
	 * Add generic filter function
	 * 
	 * See also addText(), addNumber() and addDate() for different types
	 * 
	 * @param string $name The name of the filter.
	 * @param Callable $fn The filter function will be called with Query $query, $value, array $filter 
	 * @return $this
	 */
	public function add($name, $fn) {
		$this->filters[strtolower($name)] = ['type' => 'generic', 'fn' => $fn];
		
		return $this;
	}
	
	private function validate(Query $query, array $filter) {
		$invalidFilters = array_diff(array_map('strtolower',array_keys($filter)), array_keys($this->filters));
		if(!empty($invalidFilters)) {
			throw new Exception("Invalid filters supplied for '".$query->getModel()."': '". implode("', '", $invalidFilters) ."'");
		}
	}
	
	/**
	 * Applies all filters to the query object
	 * 
	 * @param Query $criteria
	 * @param array $filter
	 */
	public function apply(Query $query, Criteria $criteria, array $filter) {
		$this->validate($query, $filter);		
		foreach($filter as $name => $value) {
			$filterConfig = $this->filters[strtolower($name)];
			
			switch($filterConfig['type']) {
				
				case 'number':					
					$range = $this->checkRange($value);
					if($range) {
						call_user_func($filterConfig['fn'], $criteria, '>=', (int) $range[0], $query, $filter);
						call_user_func($filterConfig['fn'], $criteria, '<=', (int) $range[1], $query, $filter);
					} else
					{
						$v = self::parseNumericValue($value);
						call_user_func($filterConfig['fn'], $criteria, $v['comparator'], (int) $v['query'], $query, $filter);
					}
					break;
					
				case 'date':					
					$range = $this->checkRange($value);
					if($range) {
						$range[0] = new DateTime($range[0]);
						$range[1] = new DateTime($range[1]);
						
						call_user_func($filterConfig['fn'], $criteria, '>=', $range[0], $query, $filter);
						call_user_func($filterConfig['fn'], $criteria, '<=', $range[1], $query, filter);
					} else
					{
						$v = self::parseNumericValue($value);
						$v["query"] = new DateTime($v["query"]);
						call_user_func($filterConfig['fn'], $criteria, $v['comparator'], $v["query"], $query, $filter);
					}
					break;
					
				case 'text':
					if(!is_array($value)){
						$value = [$value];
					}
					$v = array_map(function($v) {return '%'.$v.'%';}, $value);//self::parseStringValue($value);
					call_user_func($filterConfig['fn'], $criteria, "LIKE", $v, $query, $filter);
					break;
				
				case 'generic':
					call_user_func($filterConfig['fn'], $criteria, $value, $query, $filter);
					break;
			}
			
		}
	}
	
	/**
	 * Add number filter.
	 * 
	 * Supports ranges 1..4 between 1 and 4 and >=, <> != = operators
	 * 
	 * @param string $name
	 * @param function $fn Called with: Criteria $criteria, $comparator, $value, Query $query, array $filters
	 * @return $this
	 */
	public function addNumber($name, $fn) {
		$this->filters[strtolower($name)] = ['type' => 'number', 'fn' => $fn];
		
		return $this;
	}	
	
	/**
	 * Add date filter.
	 * 
	 * Supports ranges. For example last week..now,  >last year, >2019-01-01
	 * 
	 * Values are converted to DateTime objects. Supports all strtotime formats as input.
	 * 
	 * @param string $name
	 * @param function $fn Called with: Criteria $criteria, $comparator, DateTime $value, Query $query, array $filters
	 * @return $this
	 */
	public function addDate($name, $fn) {
		$this->filters[strtolower($name)] = ['type' => 'date', 'fn' => $fn];
		
		return $this;
	}	
	
	/**
	 * Add text filter.
	 * 
	 * Values are wrapped with %..% and comparator will be LIKE or NOT LIKE
	 * 
	 * @param string $name
	 * @param function $fn Called with: Criteria $criteria, $comparator, $value, Query $query, array $filters
	 * @return $this
	 */
	public function addText($name, $fn) {
		$this->filters[strtolower($name)] = ['type' => 'text', 'fn' => $fn];
		
		return $this;
	}
	
	public static function parseNumericValue($value) {
		$regex = '/\s*(>=|<=|>|<|!=|=)\s*(.*)/';
		if(preg_match($regex, $value, $matches)) {
			list(,$comparator, $v) = $matches;
		} else
		{
			$comparator = '=';
			$v = $value;
		}
		
		return ['comparator' => $comparator, 'query' => $v];
	}
	
	public static function parseStringValue($value) {
		if(!is_array($value)) {
			$value = [$value];
		}
		
		$regex = '/\s*(!=|=)?\s*(.*)/';
		if(preg_match($regex, $value, $matches)) {
			list(,$comparator, $v) = $matches;
		} else
		{
			$comparator = '=';
			$v = '%'.$value.'%';
		}
		
		return [
				['comparator' => $comparator == '=' ? 'LIKE' : 'NOT LIKE', 'query' => $v]
		];
	}
	
	private function checkRange($value) {
		//Operators >, <, =, !=,
		//Range ..
		
		$parts = array_map('trim', explode('..', $value));
		if(count($parts) > 2) {
			throw new \Exception("Invalid range. Only one .. allowed");
		}
		
		if(count($parts) == 1) {
			//no range given
			return false;
		}
		
		return $parts;
	}
}
