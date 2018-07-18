<?
# run with `phpunit DbTree.php`

$_ENV['root_folder'] = realpath(dirname(__FILE__).'/../').'/';
require $_ENV['root_folder'] . '/vendor/autoload.php';

use PHPUnit\Framework\TestCase;

use \Grithin\Debug;
use \Grithin\Db;
use \Grithin\DbTree;
use \Grithin\TempFile;


\Grithin\GlobalFunctions::init();

global $accumulation;
$accumulation = [];
function make_accumulator($accumulate){
	return function() use ($accumulate) {
		global $accumulation;
		$accumulation[] = $accumulate;
	};
}
function args_accumulator(){
	$args = func_get_args();
	global $accumulation;
	$accumulation[] = $args;
}
function accumulate_array_changes($this, $changes){
	global $accumulation;
	$accumulation[] = (array)$changes;
}

class MainTests extends TestCase{
	function run_tests($DbTree){
		$ids = [];
		$ids[] = $bob_id = $DbTree->node_append(['name'=>'bob']);

		$ids[] = $DbTree->node_append(['name'=>'bob c1'], end($ids));
		$ids[] = $DbTree->node_append(['name'=>'bob c1.1'], end($ids));
		$DbTree->node_append(['name'=>'bob c1.1.1'], end($ids));
		$DbTree->node_append(['name'=>'bob c1.1.2'], end($ids));

		$node = $DbTree->node_get($bob_id);
		$x = $DbTree->node_children_as_nested($bob_id);

		$this->assertEquals(1, count($x[0]['children']), 'bob c1.1 missing');
		$this->assertEquals(2, count($x[0]['children'][0]['children']), 'bob c1.1.* missing');
		$this->assertEquals('bob c1.1.1', $x[0]['children'][0]['children'][0]['name'], '`node_append` failed order');
		$x = $DbTree->node_children($bob_id);
		$this->assertEquals(4, count($x), '`node_children` miss-count');

		$x = $DbTree->node_immediate_children($bob_id);
		$this->assertEquals(1, count($x), '`node_immediate_children` miss-count');

		$DbTree->node_prepend(['name'=>'bob c1.1.3'], end($ids));
		$x = $DbTree->node_children_as_nested($bob_id);

		$this->assertEquals('bob c1.1.3', $x[0]['children'][0]['children'][0]['name'], '`node_prepend` failed order');


		$this->assertEquals(true, $DbTree->node_has_child($bob_id, end($ids)), '`node_has_child` failed');
		$this->assertEquals(false, $DbTree->node_has_child(end($ids), $bob_id), '`node_has_child` failed');



		$DbTree->node_append(['name'=>'bob c1.1.4'], end($ids));
		$x = $DbTree->node_children_as_nested($bob_id);
		$this->assertEquals('bob c1.1.4', end($x[0]['children'][0]['children'])['name'], '`node_append` failed order');

		$DbTree->node_prepend(['name'=>'bob c1.1.5'], end($ids));
		$x = $DbTree->node_children_as_nested($bob_id);
		$this->assertEquals('bob c1.1.5', reset($x[0]['children'][0]['children'])['name'], '`node_prepend` failed order');

		$this->assertEquals(false, $DbTree->node_has_parent($bob_id, end($ids)), '`node_has_parent` failed');
		$this->assertEquals(true, $DbTree->node_has_parent(end($ids), $bob_id), '`node_has_parent` failed');


		$c1_1_id = end($ids);
		$c1_id  = $ids[1];

		$this->assertEquals($c1_id, $DbTree->node_parent($c1_1_id)['id'], '`node_parent` failed');
		$this->assertEquals($c1_id, $DbTree->node_parent_id($c1_1_id), '`node_parent_id` failed');


		$c1_2_id = $DbTree->node_append(['name'=>'bob c1.2'], $c1_id);
		$c1_3_id = $DbTree->node_append(['name'=>'bob c1.3'], $c1_id);
		$DbTree->node_append(['name'=>'bob c1.2.1'], $c1_2_id);
		$c1_3_1_id = $DbTree->node_append(['name'=>'bob c1.3.1'], $c1_3_id);

		$DbTree->node_delete($c1_2_id);
		$children = $DbTree->node_children_as_nested($bob_id);


		$this->assertEquals(2, $children[0]['order_in'], '`node_delete` failed');
		$this->assertEquals(19, $children[0]['order_out'], '`node_delete` failed');
		$this->assertEquals(15, $children[0]['children'][1]['order_in'], '`node_delete` failed');
		$this->assertEquals(18, $children[0]['children'][1]['order_out'], '`node_delete` failed');


		$parents = $DbTree->node_parents($c1_3_1_id);
		$this->assertEquals('bob c1.3', $parents[0]['name'], '`node_parents` failed');
		$this->assertEquals('bob c1', $parents[1]['name'], '`node_parents` failed');
		$this->assertEquals('bob', $parents[2]['name'], '`node_parents` failed');

		$sue_id = $DbTree->node_prepend(['name'=>'sue']);
		$sue_c1_id = $DbTree->node_append(['name'=>'sue c1'], $sue_id);


		$moe_id = $DbTree->node_append(['name'=>'moe']);
		$moe_c1_id = $DbTree->node_append(['name'=>'moe c1'], $moe_id);

		$joe_id = $DbTree->node_append(['name'=>'joe']);
		$joe_c1_id = $DbTree->node_append(['name'=>'joe c1'], $joe_id);



		$DbTree->node_append($moe_id, $sue_id);
		$this->assertEquals([], $DbTree->tree_gaps(), '`node_append` failed on move');
		$DbTree->node_prepend($sue_id, $bob_id);
		$this->assertEquals([], $DbTree->tree_gaps(), '`node_prepend` failed on move');
		/*
		-	bob
			-	sue
				-	moe
		-	joe
		*/
		$bill_id = $DbTree->node_append(['name'=>'bill']);
		$bill_c1_id = $DbTree->node_append(['name'=>'bill c1'], $bill_id);

		$DbTree->node_move_children_to_node($bob_id, $joe_id);
		/*
		-	bob
		-	joe
			-	sue
				-	moe
		-	bill
		*/
		$this->assertEquals([], $DbTree->tree_gaps(), '`node_prepend` failed on move');
		$DbTree->node_replace_with($joe_id, $sue_id);
		/*
		-	bob
		-	sue
			-	moe
		-	bill
		*/
		$this->assertEquals([], $DbTree->tree_gaps(), '`node_prepend` failed on move');

		$count = count($DbTree->tree_all_nodes());
		$this->assertEquals(17, $count, 'count wrong');
	}

	function test_no_base_where(){
		$TempFile = new TempFile;
		$db = Db::init_force('files', ['dsn'=>'sqlite:'.$TempFile->path]);

		$db->query('CREATE TABLE "tree" (
			"id" INTEGER PRIMARY KEY AUTOINCREMENT UNIQUE,
			"name" TEXT UNIQUE,
			"order_in" INTEGER,
			"order_out" INTEGER,
			"order_depth" INTEGER,
			"id__parent" INTEGER
		)');

		$DbTree = new DbTree('tree', ['db'=>$db]);
		$this->run_tests($DbTree);
	}
	function test_base_wheres(){
		$TempFile = new TempFile;
		$db = Db::init_force('files', ['dsn'=>'sqlite:'.$TempFile->path]);

		$db->query('CREATE TABLE "tree" (
			"id" INTEGER PRIMARY KEY AUTOINCREMENT UNIQUE,
			"name" TEXT,
			"order_in" INTEGER,
			"order_out" INTEGER,
			"order_depth" INTEGER,
			"id__parent" INTEGER,
			"item_id" INTEGER
		)');

		$DbTree = new DbTree('tree', ['db'=>$db, 'where'=>['item_id'=>1]]);
		$this->run_tests($DbTree);

		$DbTree = new DbTree('tree', ['db'=>$db, 'where'=>['item_id'=>2]]);
		$this->run_tests($DbTree);
	}
}