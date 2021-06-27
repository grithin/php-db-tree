
# Db Tree

For handling tree operations within a database with order_in, order_out (for things like nested comments)

@note operations cause temporary tree infidelity.  Conseqently, functions are `protected` to force a run through __call, which turns them into transactions.



# Use

Expecting a table with the following structure

```sql

create table tree_table (
 `id` int auto_increment not null ,
 `order_in` int,
 `order_out` int,
 `order_depth` int default 0,
 `id__parent` int default 0,
 primary key (`id`),
 key order_in (`order_in`),
 key order_out (`order_in`),
 key id__parent (`id__parent`)
)
```
The table can have any additional columns.

For these examples, I'll include another column "name".


Inititialize tree instance and fill it with some hierarchy:
```php
#+ create a temporary database {
$TempFile = new \Grithin\TempFile;
$db = Db::init_force('files', ['dsn'=>'sqlite:'.$TempFile->path]);
$db->query('CREATE TABLE "tree" (
	"id" INTEGER PRIMARY KEY AUTOINCREMENT UNIQUE,
	"name" TEXT UNIQUE,
	"order_in" INTEGER,
	"order_out" INTEGER,
	"order_depth" INTEGER,
	"id__parent" INTEGER
)');
#+ }

$DbTree = new \Grithin\DbTree('tree', ['db'=>$db]);

$parent_id = $DbTree->node_append(['name'=>'bob']);
$child_sub_1_id = $DbTree->node_append(['name'=>"bob's child bill"], $parent_id);
$child_sub_1_2_id = $DbTree->node_append(['name'=>"bill's child"], $child_sub_1_id);
$child_sub_2_id = $DbTree->node_append(['name'=>"bob's 2nd child"], $parent_id);

$x = $DbTree->node_children_as_nested($parent_id);
```

`$x` will look like:
```json
[
   {
      "id": "2",
      "name": "bob's child bill",
      "order_in": "2",
      "order_out": "5",
      "order_depth": "2",
      "id__parent": "1",
      "children": [
         {
            "id": "3",
            "name": "bill's child",
            "order_in": "3",
            "order_out": "4",
            "order_depth": "3",
            "id__parent": "2"
         }
      ]
   },
   {
      "id": "4",
      "name": "bob's 2nd child",
      "order_in": "6",
      "order_out": "7",
      "order_depth": "2",
      "id__parent": "1"
   }
]
```

You should wrap $DbTree manipulation operations in try blocks in case the Db transaction fails.


## Useful functions
(see Docblocks)
-  manipualtion
   -  node_append
   -  node_prepend
   -  node_delete
   -  node_insert
   -  node_move_before_node
   -  node_move_after_node
   -  node_move_with_parent_and_index
   -  node_move_children_to_node
   -  node_replace_with
-  info
   -  node_size
   -  node_parent
   -  node_parents_sql
   -  node_parents
   -  node_has_parent
   -  node_children
   -  node_has_child
   -  node_immediate_children
   -  node_children_as_nested


