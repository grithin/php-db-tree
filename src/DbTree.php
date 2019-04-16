<?
namespace Grithin;

/* About.md
for handling order_in order_out indexed tree (for things like nested comments)

@note operations cause temporary tree infidelity so should not be run consequently.  Conseqently, functions are `protected` to force a run through __call, which turns them into transactions

# Actions
## Changing Parent
use node_append or node_prepend

*/

/* Example db table (see tests for item_id columned table)

create table tree (
 `id` int auto_increment not null ,
 `name` varchar(250),
 `order_in` int,
 `order_out` int,
 `order_depth` int default 0,
 `id__parent` int default 0,
 primary key (`id`),
 key order_in (`order_in`),
 key order_out (`order_in`),
 key id__parent (`id__parent`)
) engine innodb charset utf8;

*/


class DbTree{
	use \Grithin\testCall;
	public $db;
	public $base_where = array();


	function __construct($table, $options=[]){
		if(!$options['db']){
			$options['db'] = Db::primary();
		}
		$this->db = $options['db'];
		$this->table = $table;

		$this->base_where = (array)$options['where'];

		if($this->base_where && !isset($options['insert'])){
			$options['insert'] = $this->base_where;
		}
		$this->base_insert = (array)$options['insert'];

		$this->columns = $this->db->column_names($table);
	}
	///tree operations need to be atomic, and mutex
	function __call($fnName,$args){
		$this->__methodExists($fnName);
		$this->db->start_transaction();
		try{
			$return = call_user_func_array(array($this,$fnName),$args);
			$this->db->commit_transaction();
		}catch (\Exception $e){
			$this->db->rollback_transaction();
			throw $e;
		}
		return $return;
	}


	/* About
	technical: offset orders >= some `order_in`

	practical: used for either expanding a section of the tree (for placement of a node) or contracting a section of the tree (on node removal)
	*/
	protected function tree_offset($offset, $order_in){
		# order_in offset will only affect the current node and nodes that come after it
		$this->db->update($this->table,
			[':order_in'=> 'order_in + '.$offset],  $this->tree_where_get(['order_in?>='=>$order_in]));
		# order_out offset will affect the current node, the nodes that come after it, and the nodes that contain the current node (which have a order_out dependent upon their children)
		$this->db->update($this->table,
			[':order_out'=> 'order_out + '.$offset], $this->tree_where_get(['order_out?>='=>$order_in]));
	}


	# Expand a tree to fit a node (empty node (+2) or a childed node (+2 + 2x)), at some position, by increasing the order ins and order outs above the position
	protected function tree_expand($node, $order_in){
		$node = $this->node_return($node);
		$adjustment = $node['order_out'] ? $node['order_out'] - $node['order_in'] + 1 : 2;
		$this->tree_offset($adjustment, $order_in);
	}
	# Collapse a tree to account for removed node (empty node (-2) or a childed node (-2 - 2x)), at some position, by increasing the order ins and order outs above the position
	protected function tree_collapse($node){
		$node = $this->node_return($node);
		$adjustment = $node['order_out'] - $node['order_in'] + 1;
		$this->tree_offset(-$adjustment, $node['order_in']);
	}



	/* About
	technical: offset the order_in order_out columns, to be at the new $to['order_in'] and $to['order_depth'],  of all items within and at the $from['order_in'] and $from['order_out'] of $from

	practical: this effectively moves a node and it's tree into a place where room has already beed made.  It will leave a gat, and it is expected that before this function is used, tree_offset is used to make gap at the new position, and after this function is used, tree_offset is used to close the gap at the former position.  Note, in the case that a node is moved laterally backwards, before another smaller node, the potential overlap in $from and $to is avoided by that fact that tree_offset will move the moving node (and the $from) forward to make room prior to this function being called, and so the $to and $from will not overlap.
	*/
	/* params
	< to > < order_out is not used >
		order_in:
		order_depth:
	< from >
		order_in:
		order_out:
		order_depth:
	*/
	protected function subtree_adjust_position_state($to, $from){
		$order_adjustment = $to['order_in'] - $from['order_in'];
		$depth_adjustment = $to['order_depth'] - $from['order_depth'];
		$updates = [];
		if($order_adjustment){
			$updates[':order_in'] = 'order_in + '.$order_adjustment;
			$updates[':order_out'] = 'order_out + '.$order_adjustment;
		}
		if($depth_adjustment){
			$updates[':order_depth'] = 'order_depth + '.$depth_adjustment;
		}
		if($updates){
			$wheres = $this->tree_where_get(['order_in?>=' => $from['order_in'], 'order_out?<='=>$from['order_out']]);
			$this->db->update($this->table, $updates, $wheres);
		}
	}

	# in isolation, adjust the order_x attributes of a subtree to some new position
	protected function subtree_adjust_position_state_by_node($to, $node){
		$node = $this->node_get($node); # force get to account for whatever changes might have taken place from the offset
		return $this->subtree_adjust_position_state($to, $node);
	}


	public function tree_node_delete($fk=null){
		$this->db->delete($this->table, $this->tree_where_get());
	}

	public function tree_where_get($wheres=[]){
		return Arrays::merge($this->base_where, (array)$wheres);
	}
	public function tree_all_nodes(){
		return $this->db->rows('select * from '.$this->db->quote_identity($this->table).$this->db->where($this->tree_where_get(), false).' order by order_in');
	}

	# about: get an existing node from provided information
	/* params
		< node > < an id | a node array | a where set that identifies a node >
	*/
	public function node_get($node){
		if(Tool::isInt($node)){ # get by id
			return $this->db->row($this->table, $this->tree_where_get(['id'=>$node]), Arrays::implode(',', $this->columns));
		}elseif(is_array($node)){ # although we have what might already be a node, freshness is insisted
			if($node['id']){ # get by id
				return $this->db->row($this->table, $this->tree_where_get(['id'=>$node['id']]), Arrays::implode(',', $this->columns));
			}elseif($node['order_id']){ # get by order_id
				return $this->db->row($this->table, $this->tree_where_get(['order_in'=>$node['order_in']]), Arrays::implode(',', $this->columns));
			}else{ # perhaps we have a where set
				return $this->db->row($this->table, $this->tree_where_get($where), Arrays::implode(',', $this->columns));
			}
		}
		return (array)$node;
	}
	# depending on what was passed in, either return unchanged or run `node_get`
	public function node_return($node){
		if(is_array($node) && isset($node['order_in']) && isset($node['order_out']) && isset($node['id'])){
			return $node;
		}

		$node = $this->node_get($node);
		if(!$node){
			throw new \Exception('Could not get node');
		}
		return $node;
	}


	public function node_get_parent($node){
		return $this->db->row($this->table,$this->base_where + ['id'=>$node['id__parent']]);
	}
	# delete node and its children, and collapse tree
	protected function node_delete($node){
		$node = $this->node_return($node);

		$this->db->delete($this->table,$this->base_where + ['order_in?>='=>$node['order_in'],'order_in?<'=>$node['order_out']]);

		$this->tree_offset(-$this->node_size($node), $node['order_in']);
	}
	# calculate node size based on order_in order_out keys on passed node object
	static function node_size($node){
		return ($node['order_out'] - $node['order_in']) + 1;
	}


	# move an existing node to a sane position (order_in + id__parent + order_depth)
	/* Caution
	The $position is expected to be calculated (sane).  Use another non-"raw" function instead
	*/
	# Note: the sanity of the parent is not checked.
	/* params
	< node > < id or object >
	< position >
		order_in:
		order_depth:
		id__parent:
	*/
	protected function node_raw_move($node, $position){
		$node = $this->node_return($node);

		$size = $this->node_size($node);
		$this->tree_offset($size, $position['order_in']); # make room for the node at the target position by moving things right

		if($node['order_in'] >= $position['order_in']){
			#< if the node was moved right to make space for itself (because it came after the move target position), then remake where it is `from` with the new order_in and order_out
			$from = [
				'order_in'=>$node['order_in'] + $size,
				'order_out'=>$node['order_out'] + $size,
				'order_depth' => $node['order_depth'],
			];
		}else{
			#< node was not affected by the "make-space" offset (it existed before the move position), so the `from` is unaffected
			$from = $node;
		}
		$this->subtree_adjust_position_state($position, $from);
		$this->tree_offset(-$size, $from['order_in']);

		if($node['id__parent'] != $position['id__parent']){
			$this->db->update($this->table,['id__parent'=>$position['id__parent']],$node['id']);
		}
	}
	protected function node_create($node, $position){
		$size = 2;
		$this->tree_offset($size, $position['order_in']);
		$node['order_in'] = $position['order_in'];
		$node['order_out'] = $node['order_in'] + 1;
		$node['order_depth'] = $position['order_depth'];
		$node['id__parent'] = $position['id__parent'] ? $position['id__parent'] : 0;
		return $this->db->insert($this->table, array_merge($this->base_insert, $node));
	}


	# insert new or existing node at position by moving things right
	protected function node_insert($node,$position){
		if(Tool::is_int($node)){
			$node = $this->node_get($node);
		}
		if($node['order_in']){//node is being moved
			return $this->node_raw_move($node, $position);
		}else{# node is being inserted/created
			return $this->node_create($node, $position);
		}
	}


	# append new or existing node to a parent node or to root
	protected function node_append($node,$parent=[]){
		if(Tool::is_int($node)){
			$node = $this->node_get($node);
		}
		if($parent){
			$parent = $this->node_return($parent);
		}

		if(!$parent['order_out']){# no parent, add to end of top level
			$lastOrderIn = $this->db->value($this->table,$this->base_where,'order_out','order_out desc');
			$position['order_in'] = $lastOrderIn + 1;
			$position['order_depth'] = 1;
			$position['id__parent'] = 0;
		}else{
			$position['order_in'] = $parent['order_out'];
			$position['order_depth'] = $parent['order_depth'] + 1;
			$position['id__parent'] = $parent['id'];
		}
		return $this->node_insert($node,$position);
	}

	# prepend new or existing node to a parent node or to root
	protected function node_prepend($node,$parent=[]){
		if(Tool::is_int($node)){
			$node = $this->node_get($node);
		}
		if($parent){
			$parent = $this->node_return($parent);
		}

		if(!$parent['order_in']){# no parent, add to beginning of top level
			$position['order_in'] = 1;
			$position['order_depth'] = 1;
			$position['id__parent'] = 0;
		}else{
			$position['order_in'] = $parent['order_in'] + 1;
			$position['order_depth'] = $parent['order_depth'] + 1;
			$position['id__parent'] = $parent['id'];
		}
		return $this->node_insert($node,$position);
	}
	# move a node to the left of (before) another node
	protected function node_move_before_node($node, $before_node){
		$node = $this->node_return($node);
		$before_node = $this->node_return($before_node);
		$this->node_raw_move($node, $before_node);
	}
	# move a node to the right of (after) another node
	protected function node_move_after_node($node, $after_node){
		$node = $this->node_return($node);
		$after_node = $this->node_return($after_node);
		$position = [
				'id__parent' => $after_node['id__parent'],
				'order_depth' => $after_node['order_depth'],
				'order_in' => $after_node['order_out'] + 1,
			];
		$this->node_raw_move($node, $position);
	}




	public function node_parent($node,$columns=[]){
		$node = $this->node_return($node);
		if(!$columns){
			$columns = $this->columns;
		}
		if(is_array($columns)){
			$columns = implode(', ',array_map([$this->db,'quote_identity'],$columns));
		}

		return $this->db->row(['select '.$columns.'
			from '.$this->db->quote_identity($this->table).'
			where id = ?', [$node['id__parent']]]);
	}
	public function node_parent_id($node){
		$node = $this->node_return($node);
		return $node['id__parent'];
	}


	//sql to get all columns for parents (enclose it in other sql for further restrictions)
	public function node_parent_sql($node){
		$node = $this->node_return($node);

		$where = $this->db->where($this->tree_where_get([
			'order_in?<' => $node['order_in'],
			'order_out?>' => $node['order_out']
		]));
		return 'select *
			from '.$this->db->quote_identity($this->table).$where.' ';
	}
	///returns parents in order of desc depth
	public function node_parents($node,$columns=[]){
		$node = $this->node_return($node);
		if(!$columns){
			$columns = $this->columns;
		}
		if(is_array($columns)){
			$columns = implode(', ',array_map([$this->db,'quote_identity'],$columns));
		}

		return $this->db->rows('select '.$columns.'
			from ('.$this->node_parent_sql($node).') t1
			order by t1.order_depth desc');

	}
	public function node_has_parent($node,$parent){
		$node = $this->node_return($node);
		$parent = $this->node_return($parent);

		if($node['order_in'] > $parent['order_in'] && $node['order_out'] < $parent['order_out']){
			return true;
		}
		return false;
	}
	public function node_children($node=null,$columns=[]){
		if(!$columns){
			$columns = $this->columns;
		}
		if($node){
			$node = $this->node_return($node);
			$where = ['order_in?>' => $node['order_in'],'order_out?<'=>$node['order_out']];
		}else{
			$where = [];
		}

		return $this->db->rows($this->table,
			$this->tree_where_get($where),
			$columns, 'order_in asc');
	}
	public function node_has_child($node,$child){
		$node = $this->node_return($node);
		$child = $this->node_return($child);
		if($node['order_in'] < $child['order_in'] && $node['order_out'] > $child['order_out']){
			return true;
		}
		return false;
	}
	public function node_immediate_children($node=null,$columns=[]){
		if(!$columns){
			$columns = $this->columns;
		}
		if($node){
			$node = $this->node_return($node);
			$where = ['order_in?>' => $node['order_in'],'order_out?<'=>$node['order_out'], 'order_depth = '.($node['order_depth']+1)];
		}else{
			$where = [];
		}

		return $this->db->rows($this->table,
			$this->tree_where_get($where),
			$columns, 'order_in asc');
	}
	public function node_children_as_nested($node=null,$columns=[]){
		if(!$columns){
			$columns = $this->columns;
		}
		$children = $this->node_children($node,$columns);
		$depth = $baseDepth = $children[0]['order_depth'];
		$lineage = [];
		foreach($children as $k=>&$child){
			//note, + 2 to acccount for baseDepth assignment (consder baseDepth key to array containing immediate children)
			$lineage[$child['order_depth'] + 1]['children'][] = &$child;
			$lineage[$child['order_depth'] + 2] =&$child;
			if($child['order_depth'] == $baseDepth){
				$lineage[$baseDepth][] =&$child;
			}
		}
		return $lineage[$baseDepth];
	}

	# find gaps in order_in order_out sequence
	public function tree_gaps(){
		$all = $this->db->rows('select order_in, order_out, order_depth, name from '.$this->db->quote_identity($this->table).$this->db->where($this->tree_where_get(), false).' order by order_in');
		$orders = [];
		$order_ins = [];
		$order_outs = [];
		foreach($all as $item){
			$orders[] = $item['order_in'];
			$orders[] = $item['order_out'];
		}
		sort($orders);
		$previous = 0;
		$gaps = [];
		foreach($orders as $order){
			if($order - 1 != $previous){
				$gaps[] = $previous+1;
			}
			$previous = $order;
		}
		return $gaps;
	}
	# append the children from one node to another
	protected function node_move_children_to_node($from_node, $to){
		$from_node = $this->node_return($from_node);
		$from = [
			'id__parent' => $from_node['id'],
			'order_in' => $from_node['order_in'] + 1,
			'order_out' => $from_node['order_out'] - 1,
			'order_depth' => $from_node['order_depth'] + 1,
		];

		$to = $this->node_return($to);

		if($from_node['id'] == $to['id']){ # same node
			return;
		}


		$size = $this->node_size($from);
		if(!$size){ # no children
			return;
		}

		$position = [
			'order_in' => $to['order_out'],
			'order_depth' => $to['order_depth'] + 1,
			'id__parent' => $to['id']
		];

		$this->tree_offset($size, $position['order_in']);
		if($from['order_in'] >= $position['order_in']){ # account for tree_offset
			$from = [
				'order_in'=>$from['order_in'] + $size,
				'order_out'=>$from['order_out'] + $size,
				'order_depth'=>$from['order_depth']
			];
		}
		$this->subtree_adjust_position_state($position, $from);
		$this->tree_offset(-$size, $from['order_in']);

		$this->db->update($this->table, ['id__parent'=>$position['id__parent']], $this->tree_where_get(['id__parent'=>$from['id__parent']]));
	}
	/*
	replace one node with another, even if replacee is parent
	*/
	/* Ex
	replace `joe` with `sue`

	-	bob
	-	joe
		-	jane
		-	sue
			-	moe
	-	bill

	becomes

	-	joe
	-	sue
		-	moe
		-	jane
	-	bill
	*/
	protected function node_replace_with($replacee, $replacer){
		$replacee = $this->node_return($replacee);
		$replacer = $this->node_return($replacer);

		# put replacer in front of
		$this->node_raw_move($replacer, $replacee);

		# the insert changes the node placements, so re-get
		$replacee = $this->node_get($replacee);
		$replacer = $this->node_get($replacer);

		# move children to replacer
		$this->node_move_children_to_node($replacee, $replacer);
		# delete replacee
		$this->node_delete($replacee['id']); # use id so delete regets node for updated placement
	}
}
