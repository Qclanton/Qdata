<?php
namespace Qdata;

abstract class Structure {
    public $Db;
    public $table;
    public $fields;
    public $ties;
    public $attaches;
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
            if (isset($field['isDeletedMarker']) && $field['isDeletedMarker'] === true) {
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
        
        $this->resetTies();
        $this->resetAttaches();
        
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
                    $length = (isset($field['length']) ? "({$field['length']})" : "(255)");
                    break;
            }
            
            
            
            if ($name === $this->primaryField) {
                if ($type === "BIGINT") {
                    $signed = "UNSIGNED";
                    $additions = "AUTO_INCREMENT";
                }           
                
                $primaryFieldQuery = "PRIMARY KEY (`{$name}`)";
            }
            
            
            
            if ($name === $this->deletedMarkerColumn) {
                $type = "TINYINT";
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
                $indexesQuery .= ", UNIQUE KEY `i_{$name}` (`{$name}`)";
            }
            
            
            
            if (isset($field['isIndex']) && $field['isIndex'] === true) {
                $indexesQuery .= ", KEY `i_{$name}` (`{$name}`)";
            }



            $fieldsQuery .= "`{$name}` {$type}{$length} {$signed} {$nullable} {$additions}," . PHP_EOL;
        }
        
        
        
        if (!empty($this->indexes)) {
            foreach ($this->indexes as $name=>$index) {
                $unique = (isset($index['isUnique']) && $index['isUnique'] === true ? "UNIQUE" : "");
                $indexesQuery .= ", {$unique} KEY `i_{$name}` (`" . implode("`,`", $index['fields']) . "`)";
            }
        }
        
        
        
        $fieldsQuery .= $primaryFieldQuery . $indexesQuery;
        $query = "CREATE TABLE IF NOT EXISTS `{$this->table}` ($fieldsQuery) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        
        
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
        
        return ($result !== false ? $this->Db->insert_id : false);
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
    
    
    
    
    public function resetTies() 
    {
        $this->ties = [
            'fields' => [],
            'connections' => ""
        ];
        
        return $this;
    }
    
    public function tie($table, $connection, array $fields=[], $joinType="JOIN") 
    {   
        if (is_array($connection)) {
            $connection = implode(" AND ", $connection);
        } 
    
        $this->ties['fields'] = array_merge($this->ties['fields'], $fields);
        $this->ties['connections'] .= "{$joinType} {$table} ON ($connection) ";
        
        return $this;
    }
    
    
    
    
    
    public function resetAttaches()
    {
        $this->attaches = [];
    }
    
    public function attach($entity, Structure $Structure, $entityField=null, $attachField=null, $type="ONE_ONE", Structure $ReferenceStructure=null, $referenceField=null) 
    {
        if (is_null($entityField)) {
            $entityField = "{$entity}_id";
        }
        
        $this->attaches[] = [$entity, $Structure, $entityField, $attachField, $type, $ReferenceStructure, $referenceField];
        
        return $this;
    }
    
    protected function applyAttaches($selection) 
    {
        if (!empty($this->attaches)) {
            if (is_array($selection)) {
                foreach ($selection as &$exemplar) {
                    $exemplar = $this->applyAttachesToExemplar($exemplar);
                }            
            } else {
                $selection = $this->applyAttachesToExemplar($selection);
            }
        }
        
        $this->resetAttaches();
        
        return $selection;
    }
    
    protected function applyAttachesToExemplar($exemplar)
    {
        foreach ($this->attaches as $attach) {
            list($entity, $Structure, $entityField, $attachField, $type, $ReferenceStructure, $referenceField) = $attach;
            
            switch ($type) {
                case "ONE_ONE":
                    $exemplar->{$entity} = $Structure->get($exemplar->{$entityField});
                    break;
                    
                case "ONE_MANY":
                    $exemplar->{$entity} = $Structure->get(["{$Structure->table}.`{$attachField}`='{$exemplar->{$entityField}}'"]);
                    break;
                
                case "MANY_MANY":
                    $Structure->tie(
                        $ReferenceStructure->table, 
                        "`{$ReferenceStructure->table}`.`{$referenceField}`=`{$Structure->table}`.`{$entityField}`"
                    );
                    
                    $exemplar->{$entity} = $Structure->get(["{$ReferenceStructure->table}.`{$attachField}`='{$exemplar->{$entityField}}'"]);
                    break;
            }
        }

        return $exemplar;
    }
    
    
    
    
    
    
    public function tiedAttach($entity, Structure $Structure, $entityField=null, $attachField=null, $type="ONE_ONE") 
    {
        if (is_null($entityField)) {
            $entityField = "{$entity}_id";
        }
        
        $this->tiedAttaches[] = [$entity, $Structure, $entityField, $attachField, $type];
        
        return $this;
    }
    
    public function applyTiedAttaches($query)
    {
        $this->Db->query("SET SESSION group_concat_max_len = 20000000000");
        list($select, $restOfQuery) = explode("FROM {$this->table}", $query);
        $joins = "";
        
        
        
        foreach ($this->tiedAttaches as $attach) {
            list($entity, $Structure, $entityField, $attachField, $type) = $attach;
            $alias = "table_{$entity}";            
            
            $fieldsJson = "";
            $iterator = 1;
            
            foreach ($Structure->fields as $filedName=>$field) {
                $comma = (count($Structure->fields) == $iterator ? "" : ",");
                
                $fieldsJson .= (in_array($field['type'], ["%d", "%f"])
                    ? "'\"{$filedName}\":\"',  {$alias}.`{$filedName}`, '\"{$comma}',"
                    : "'\"{$filedName}\":\"',  REPLACE({$alias}.`{$filedName}`, '\"', '\\\\\"'), '\"{$comma}',"
                );

                $iterator++;
            }
            
            
            
            switch ($type) {
                case "ONE_ONE":
                    $this->defaultExemplar->{$entity} = null;
                    $select .= ", GROUP_CONCAT(CONCAT('{', {$fieldsJson} '}')) AS {$entity}";
                    break;
                
                case "ONE_MANY":
                    $this->defaultExemplar->{$entity} = [];
                    $select .= ", CONCAT('[', GROUP_CONCAT(CONCAT('{', {$fieldsJson} '}')), ']') AS {$entity}";
                    break;                
            }
            
            
            
            $joins .= " JOIN {$Structure->table} {$alias} ON ({$alias}.`{$attachField}`={$this->table}.`{$entityField}`) ";
        }
        
        $query = "{$select} FROM {$this->table} {$joins} {$restOfQuery} GROUP BY {$this->table}.`{$entityField}`";
        
        return $query;
    }
    
    
    
    
    
    
    public function search($criterion=null)
    {
        $Structure = clone $this;

        return $Structure->get($criterion);
    }
    
    public function get($criterion=null) 
    {
        return (is_array($criterion) || is_object($criterion) || is_null($criterion) ? $this->getAll($criterion) : $this->getOne($criterion));    
    }
    
    public function getOne($uniqueValue, $returnDefault=true, $field=null, $type="%s", $showDeleted=true)
    {
        $field = (in_array($field, array_keys($this->fields)) ? $field : $this->primaryField);
        $type = ($field !== $this->primaryField ? $type : $this->primaryFieldType);        
        $query = "SELECT {$this->table}.*" . (!empty($this->ties['fields']) ? ", " . implode(", ", $this->ties['fields']) : "");
        $query .= " FROM {$this->table} {$this->ties['connections']} WHERE {$this->table}.`{$field}`={$type}";
        $this->resetTies();
        
        if (!$showDeleted && isset($this->fields[$this->deletedMarkerColumn])) {
            $query .= "AND `{$this->deletedMarkerColumn}`='0'";
        }
        
        if (!empty($this->tiedAttaches)) {
            $tiedAttachQuery = $this->applyTiedAttaches($query);
            // d($tiedAttachQuery);
        }
        
        $exemplar = $this->Db->get_row($this->Db->prepare($query, $uniqueValue));
        
        if (empty($exemplar) && $returnDefault) { 
            $exemplar = $this->defaultExemplar; 
        }

        
        
        return $this->applyAttaches($exemplar);
    }
    
    protected function prepareQuery($criterion=null)
    {
        $fieldsList = (empty($criterion['fields']) ? "{$this->table}.*" : implode(", ", $criterion['fields']));         
        $query = "SELECT {$fieldsList}" . (!empty($this->ties['fields']) ? ", " . implode(", ", $this->ties['fields']) : "");
        $query .= " FROM {$this->table} {$this->ties['connections']}";
        $this->resetTies();
        
        
        
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
                $query .= " ORDER BY {$criterion['orderby']}";
                
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
        
        return $this->applyAttaches($exemplars);
    }
    
    public function count($criterion=null)
    {
        if (is_array($criterion) || is_object($criterion) || is_null($criterion)) {
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
        return $this->delete($id, true);
    }
    
    public function delete($id, $constatly=false) 
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
}
