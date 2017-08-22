<?php

namespace Automatorm\Generator;

use Automatorm\Exception;
use Automatorm\Orm\Schema;

class Model
{
    const CURRENT_VERSION = 2;

    public static function generate($path, Schema $schema)
    {
        $namespace = $schema->namespace;
        
        foreach ($schema->model as $model) {
            $updates = "";
            
            if ($model['type'] === 'table') {
                $classname = Schema::camelCase($model['table_name']);
                $filename = $path . DIRECTORY_SEPARATOR . $classname . '.php';
                if (file_exists($filename)) {
                    continue;
                } // Skip existing files
                
                foreach ($model['columns'] as $col => $coltype) {
                    if ($col === 'id') {
                        continue;
                    }
                    if (substr($col, -3) == '_id') {
                        $fk = substr($col, 0, -3);
                        if (key_exists($fk, (array) $model['one-to-one'])) {
                            $updates .= "        // 1-1 map not supported yet: {$col}\n";
                        } elseif (key_exists($fk, (array) $model['one-to-many'])) {
                            $fkclass = Schema::camelCase($model['one-to-many'][$fk]['table']);
                            $updates .= "        \$db->{$fk} = {$fkclass}::getAll(\$data['{$col}']);\n";
                        } elseif (key_exists($fk, (array) $model['many-to-one'])) {
                            $fkclass = Schema::camelCase($model['many-to-one'][$fk]['table']);
                            $updates .= "        \$db->{$fk} = {$fkclass}::get(\$data['{$col}']);\n";
                        } elseif (key_exists($fk, (array) $model['many-to-many'])) {
                            $fkclass = Schema::camelCase($model['many-to-many']['connections'][0]['table']);
                            $updates .= "        \$db->{$fk} = {$fkclass}::getAll(\$data['{$col}']);\n";
                        } else {
                            $updates .= "        \$db->{$col} = \$data['{$col}'];\n";
                        }
                    } else {
                        $updates .= "        \$db->{$col} = \$data['{$col}'];\n";
                    }
                }
                
                $php = <<<CODE
<?php

namespace {$namespace};

use Automatorm\Orm\Model;

class {$classname} extends Model
{
    public static function create(\$data)
    {
        \$db = static::newData();
$updates
        return static::commitNew(\$db);
    }
    
    public function update(\$data)
    {
        \$db = \$this->data();
$updates
        return \$this->commit(\$db);
    }
    
    public function jsonSerialize()
    {
        return ['id' => \$this->id, '_class' => '{$classname}'];
    }
}
CODE;

                file_put_contents($filename, $php);
                
                echo $classname . ".php:created\n";
            }
        }
    }
}
