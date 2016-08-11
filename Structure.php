<?php
namespace Qdata;

abstract class Structure {
    public $Db;
    public $table;
    public $fields;
    public $defaultExemplar;
    public $valuesTypes;
    public $editableFields;
    public $deletedMarkerColumn = null;
    public $primaryField = "id";
    public $primaryFieldType = "%d";
    public $dateFields = [];
    
    
    
    
    
    protected function defineDefaultExemplar() 
    {
        $exemplar = [];
        
        foreach ($this->fields as $name=>$field) {
            $exemplar[$name] = $field['default'];
        }
        
        $this->defaultExemplar = (object)$exemplar;
    }
    
    protected function defineValuesTypes() 
    {
        $types = [];
        
        foreach ($this->fields as $name=>$field) {
            $types[] = $field['type'];
        }
        
        $this->valuesTypes = $types;
    }
    
    protected function defineEditableFields() 
    {
        $editableFields = [];    
                
        foreach ($this->fields as $name=>$field) {
            if (!isset($field['isEditable']) || $field['isEditable'] === true) {
                $editableFields[] = "`{$name}`={$field['type']}";
            }
        }
        
        $this->editableFields = $editableFields;
    }
    
    protected function defineDeletedMarkerColumn() 
    {
        foreach ($this->fields as $name=>$field) {
            if (isset($this->fields['isDeletedMarker']) && $field['isDeletedMarker'] === true) {
                $this->deletedMarkerColumn = $name;
            }
        }
    }
    
    protected function definePrimaryField() 
    {        
        foreach ($this->fields as $name=>$field) {
            if (isset($field['isPrimary']) && $field['isPrimary'] === true) {
                $this->primaryField = $name;
                $this->primaryFieldType = $field['type'];
            }
        }    
    }
    
    protected function defineDateFields() 
    {        
        foreach ($this->fields as $name=>$field) {
            if (isset($field['isDate']) && $field['isDate'] === true) {
                $this->dateFields[$name] = $field;
            }
        }
    }        
    
    public function __construct() 
    {    
        global $wpdb;    
        
        $this->Db = $wpdb;
        $this->table = $this->Db->prefix . $this->table;
        
        $this->defineDefaultExemplar();
        $this->defineValuesTypes();
        $this->defineEditableFields();
        $this->defineDeletedMarkerColumn();
        $this->definePrimaryField();
        $this->defineDateFields();
    }
    
    
    
    
    public function createTable()
    {
        $fieldsQuery = "";
        $primaryFieldQuery = "";
        $indexesQuery = "";
        
        
        
        foreach ($this->fields as $name=>$field) {
            $signed = "";
            $nullable = "NOT NULL";
            $additions = "";
            
            
            
            switch ($field['type']) {
                case "%d":
                    $type = "BIGINT";
                    $length = (isset($field['length']) ? "({$field['length']})" : "(20)");
                    break;
                    
                case "%f":
                    $type = "FLOAT";
                    $length = (isset($field['length']) ? "({$field['length']})" : "(11,4)");
                    break;
                
                case "%s":
                    $type = "VARCHAR";
                    $length = "(255)";
                    break;
            }
            
            
            
            if ($name === $this->primaryField) {
                if ($type === "BIGINT") {
                    $signed = "UNSIGNED";
                    $additions = "AUTO_INCREMENT";
                }           
                
                $primaryFieldQuery = "PRIMARY KEY (`{$name}`)";
            }
            


            if (isset($field['isDate']) && $field['isDate'] === true) {
                $type = "DATETIME";
                $length = "";
            }
            
            
            
            if (isset($field['isText']) && $field['isText'] === true) {
                $type = "TEXT";
                $length = "";
            }
            
            
            
            if (
                (isset($field['nullable']) && $field['nullable'] === true) || 
                (array_key_exists("default", $field) && $field['default'] === null)
            ) {
                $nullable = "";
            }
            
            
            
            if (
                (array_key_exists("default", $field)) && 
                ($name !== $this->primaryField) &&
                (!isset($field['isText']) || $field['isText'] === false) && 
                (!isset($field['isDate']) || $field['isDate'] === false)
            ) {
                $additions = "DEFAULT " . ($field['default'] === null ? "NULL" : "'{$field['default']}'");
            }
            
            
            
            if (isset($field['isUnique']) && $field['isUnique'] === true) {
                $indexesQuery = ", UNIQUE KEY `i_{$name}` (`{$name}`)";
            }



            $fieldsQuery .= "`{$name}` {$type}{$length} {$signed} {$nullable} {$additions}," . PHP_EOL;
        }
        
        
        
        $fieldsQuery .= $primaryFieldQuery . $indexesQuery;
        $query = "CREATE TABLE `{$this->table}` ($fieldsQuery) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        
        
        return $this->Db->query($query);
    }
    
    
    
    
    
    public function set($data) 
    {
        return (is_object($data) ? $this->setOne($data) : $this->setAll($data));
    }
    
    protected function setOne($exemplar) 
    { 
        if (empty($exemplar->{$this->primaryField})) { 
            $exemplar->{$this->primaryField} = null; 
        }
        
        foreach ($this->fields as $name=>$field) {
            if (!isset($exemplar->{$name})) {
                $exemplar->{$name} = $this->defaultExemplar->{$name};
            }
        }
        
        if (!empty($this->dateFields)) {
            foreach ($this->dateFields as $name=>$field) {
                if ($exemplar->{$name} === $field['default']) {
                    $exemplar->{$name} = date("Y-m-d H:i:s");
                }
            }
        }
        
        
        
        $query = "INSERT INTO {$this->table} VALUES (" . implode(",", $this->valuesTypes) . ")";
        
        if (!empty($this->editableFields))    {
            $query .= " ON DUPLICATE KEY UPDATE `{$this->primaryField}`=LAST_INSERT_ID({$this->primaryField}), " . implode(",", $this->editableFields);
        }



        $params = [$query];
        
        foreach ($this->fields as $name=>$field) { 
            $params[] = $exemplar->{$name}; 
        }
        
        foreach ($this->fields as $name=>$field) { 
            if (!isset($field['isEditable']) || $field['isEditable'] === true) { 
                $params[] = $exemplar->{$name}; 
            } 
        }
        
        
        
        $result = $this->Db->query(call_user_func_array([$this->Db, "prepare"], $params));
        
        return ($result ? $this->Db->insert_id : false);
    }
    
    protected function setAll($exemplars) 
    {
        $result = false;
        
        foreach ($exemplars as $exemplar) {
            $exemplar = (object)$exemplar;
            
            if (!isset($exemplar->{$this->primaryField})) { 
                $exemplar->{$this->primaryField} = null; 
            }

            $existedExemplar = $this->get($exemplar->{$this->primaryField});
            $exemplar = (object)array_merge((array)$existedExemplar, (array)$exemplar);
            
            $result = $this->setOne($exemplar); 
        }
        
        return $result;
    }
    
    
    
    
    
    public function get($criterion=false) 
    {
        return (is_array($criterion) || is_object($criterion) || !$criterion ? $this->getAll($criterion) : $this->getOne($criterion));    
    }
    
    public function getOne($uniqueValue, $returnDefault=true, $field=null, $type="%s", $showDeleted=true)
    {
        $field = (in_array($field, array_keys($this->fields)) ? $field : $this->primaryField);
        $type = ($field !== $this->primaryField ? $type : $this->primaryFieldType);
        
        $query = "SELECT * FROM {$this->table} WHERE `{$field}`={$type}";
        
        if (!$showDeleted && isset($this->fields[$this->deletedMarkerColumn])) {
            $query .= "AND `{$this->deletedMarkerColumn}`='0'";
        }
        
        $exemplar = $this->Db->get_row($this->Db->prepare($query, $uniqueValue));
        
        if (empty($exemplar) && $returnDefault) { 
            $exemplar = $this->defaultExemplar; 
        }
        
        return $exemplar;
    }
    
    protected function prepareQuery($criterion=null, $fields=[])
    {
        $fields = (array)$fields;
        $fieldsList = (empty($fields) ? "*" : implode(", ", $fields));         
        $query = "SELECT {$fieldsList} FROM {$this->table}";
        
        
        
        if (!empty($criterion)) {
            if (isset($criterion[0]) && count($criterion) == 1) {
                $criterion['custom'] = $criterion[0];
            }
            
            if (isset($criterion['custom'])) {
                $query .= " WHERE {$criterion['custom']}";
            }
            
            
            
            if (isset($criterion['confines'])) {
                $query .= " WHERE 1=1";
                
                foreach ($criterion['confines'] as $confine) {
                    $query .= " AND {$confine}";
                }
            }
            
            
            
            if (isset($criterion['orderby'])) {
                $query .= " ORDER BY `{$criterion['orderby']}`";
                
                if (isset($criterion['order'])) {
                    $query .= " {$criterion['order']}";
                }
            }
            
            if (isset($criterion['limit'])) {
                $limitstart = (isset($criterion['limitstart']) ? $criterion['limitstart'] : 0);
                $query .= " LIMIT {$limitstart}, {$criterion['limit']}";
            }  
        } 
        
        
        
        return $query;   
    }
    
    public function getAll($criterion=null) 
    {
        $query = $this->prepareQuery($criterion);
        $exemplars = $this->Db->get_results($query);        
        
        return $exemplars;
    }
    
    public function count($criterion=null)
    {
        if (is_array($criterion) || is_object($criterion) || !$criterion) {
            $query = $this->prepareQuery($criterion, "COUNT({$this->primaryField})");
            $quantity = $this->Db->get_var($query);            
            $limitstart = (isset($criterion['limitstart']) ? $criterion['limitstart'] : 0);          
   
            return (isset($criterion['limit']) && ($criterion['limit'] - $limitstart) < $quantity
                ? $criterion['limit']-$limitstart
                : $quantity
            );
        } 
        
        return 1;
    }
    
    
    
    
    public function deleteConstatly($id) 
    {
        $this->delete($id, true);
    }
    
    public function delete($id, $constatly = false) 
    {
        return (($constatly || is_null($this->deletedMarkerColumn)) ? $this->remove($id) : $this->markAsDeleted($id));
    }
    
    protected function markAsDeleted($id) 
    {
        $query = "UPDATE {$this->table} SET `{$this->deletedMarkerColumn}`='1' WHERE `{$this->primaryField}`={$this->primaryFieldType}";
        $result = $this->Db->query($this->Db->prepare($query, $id));
        
        return $result;
    }
    
    protected function remove($id) 
    {
        $query = "DELETE FROM {$this->table} WHERE `{$this->primaryField}`={$this->primaryFieldType}";        
        $result = $this->Db->query($this->Db->prepare($query, $id));
        
        return $result;
    }    
    
    public function restore($id) 
    {
        $query = "UPDATE {$this->table} SET `{$this->deletedMarkerColumn}`='0' WHERE `id`={$this->primaryFieldType}";
        $result = $this->Db->query($this->Db->prepare($query, $id));
        
        return $result;    
    }
    
    
    
    
    
    public function simplify($objectsList, $value, $key=null, $type="object") 
    {
        $simplified = [];
        
        foreach ($objectsList as $object) {
            $object = (object)$object;
            
            if (empty($key)) {
                $simplified[] = $object->{$value};
            } else {
                $simplified[$object->{$key}] = $object->{$value};
            }    
        }
        
        if ($type == "object") { 
            $simplified = (object)$simplified; 
        }
        
        return $simplified;
    }
}
