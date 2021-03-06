<?php namespace OrmExtension\DataMapper;

use ArrayIterator;
use Config\Database;
use DateTime;
use DateTimeZone;
use DebugTool\Data;
use OrmExtension\Extensions\Entity;
use OrmExtension\Extensions\Model;
use OrmExtension\Interfaces\OrmEventsInterface;
use Traversable;
use Config\OrmExtension;

trait EntityTrait {

    abstract function getModel(): Model;

    public function exists() {
        return $this->id > 0 || (isset($this->all) && count($this->all));
    }

    // <editor-fold desc="Find">

    public function find($id = null) {
        $entity = $this->getModel()->find($id);
        foreach(get_object_vars($entity) as $name => $value)
            $this->{$name} = $value;
        return $entity;
    }

    // </editor-fold>

    // <editor-fold desc="Save (Insert/Update)">

    public function hasChange(): bool {
        $model = $this->getModel();
        try {
            $new = $model::classToArray($this);
            $old = $this->stored;
            foreach($new as $field => $value) {
                $newValue = (string)$value;
                $oldValue = isset($old[$field]) ? (string)$old[$field] : '';
                if($newValue === $oldValue) {
                    // No change
                } else {
                    // Change
                    //Data::debug(get_class($this), "change", $field, $newValue, $oldValue);
                    return true;
                }

            }
        } catch(\ReflectionException $e) {

        }
        return false;
    }

    /**
     * @param Entity|null $related
     * @param string|null $relatedField
     */
    public function save($related = null, $relatedField = null) {
        if($related instanceof Entity) {
            if($related->exists()) {
                $this->saveRelation($related, $relatedField);
            }
        } else {
            $result = $this->getModel()->save($this);
            if(is_numeric($result))
                $this->id = $result;
        }
    }

    /**
     * @param Entity $related
     * @param string|null $relationName
     */
    public function saveRelation($related, $relationName = null) {
        if(!$this->exists() |! $related->exists()) return;

        if(!$relationName) $relationName = get_class($related->getModel());

        $thisModel = $this->getModel();
        $relatedModel = $related->getModel();

        $relation = $thisModel->getRelation($relationName);
        if(empty($relation)) return;
        $relation = $relation[0];

        $relationShipTable = $relation->getRelationShipTable();

        if($relationShipTable == $thisModel->getTableName()) {
            if(in_array($relation->getJoinOtherAs(), $thisModel->getTableFields())) {

                // Check if opposite relation is hasOne
                $opposite = $relatedModel->getRelation($relation->getOtherField());
                if(!empty($opposite) && $opposite[0]->getType() == RelationDef::HasOne)
                    $related->deleteRelation($this, $relation->getOtherField());

                $this->{$relation->getJoinOtherAs()} = $related->id;
                $this->save();
            }
        } else if($relationShipTable == $relatedModel->getTableName()) {
            if(in_array($relation->getJoinSelfAs(), $relatedModel->getTableFields())) {

                // Check if this relation is hasOne
                if($relation->getType() == RelationDef::HasOne)
                    $this->deleteRelation($related, $relationName);

                $related->{$relation->getJoinSelfAs()} = $this->id;
                $related->save();
            }
        } else {

            Database::connect()
                ->table($relationShipTable)
                ->insert([
                    $relation->getJoinSelfAs()  => $this->id,
                    $relation->getJoinOtherAs() => $related->id
                ]);

        }
    }

    // </editor-fold>

    // <editor-fold desc="Delete">

    public function deleteAll() {
        /** @var Entity $item */
        foreach($this as $item) $item->delete();
    }

    /**
     * @param Entity|null $related
     */
    public function delete($related = null) {
        if($this->exists()) {
            if(is_null($related)) {

                $thisModel = $this->getModel();
                if(in_array('deletion_id', $this->getModel()->getTableFields())) {
                    $name = OrmExtension::$entityNamespace . 'Deletion';
                    if(class_exists($name)) {
                        /** @var Entity $deletion */
                        $deletion = new $name();
                        $deletion->save();
                        $this->deletion_id = $deletion->id;
                        $this->save();
                    }
                } else
                    $thisModel->delete($this->id);

                if($thisModel instanceof OrmEventsInterface && $this instanceof Entity)
                    $thisModel->postDelete($this);

            } else {
                $this->deleteRelation($related);
            }
        }
    }

    /**
     * @param Entity|string $related
     * @param string|null $relationName
     */
    public function deleteRelation($related, $relationName = null) {
        if(!$relationName) $relationName = get_class($related->getModel());

        $thisModel = $this->getModel();
        if(is_string($related))
            $relatedModel = new $related();
        else
            $relatedModel = $related->getModel();

        $relation = $thisModel->getRelation($relationName);
        if(empty($relation)) return;
        $relation = $relation[0];

        $relationShipTable = $relation->getRelationShipTable();

        if($relationShipTable == $thisModel->getTableName()) {
            if(in_array($relation->getJoinOtherAs(), $thisModel->getTableFields())) {
                $this->{$relation->getJoinOtherAs()} = null;
                $this->save();
            }
        } else if($relationShipTable == $relatedModel->getTableName()) {
            if(in_array($relation->getJoinSelfAs(), $relatedModel->getTableFields())) {
                if(is_string($related)) {
                    // TODO Handle updated and updated_by_id
                    Database::connect()
                        ->table($relationShipTable)
                        ->update(
                            [$relation->getJoinSelfAs() => 0],
                            [$relation->getJoinSelfAs() => $this->id]);
                } else {
                    $related->{$relation->getJoinSelfAs()} = null;
                    $related->save();
                }
            }
        } else {

            Database::connect()
                ->table($relationShipTable)
                ->delete([
                    $relation->getJoinSelfAs()  => $this->id,
                    $relation->getJoinOtherAs() => $related->id
                ]);

        }

        unset($this->{$relation->getSimpleName()});
        $this->resetStoredFields();

        if($thisModel instanceof OrmEventsInterface && $this instanceof Entity && $related instanceof Entity)
            $thisModel->postDeleteRelation($this, $related);
    }

    // </editor-fold>

    // <editor-fold desc="Print">

    public function allToArray() {
        $items = [];
        foreach($this as $item)
            $items[] = $item->toArray();
        return $items;
    }

    public function toArray(bool $onlyChanged = false, bool $cast = true): array {
        $item = [];

        // Fields
        $fields = ModelDefinitionCache::getFieldData($this->getSimpleName());
        foreach($fields as $fieldData) {
            $field = $fieldData->name;
            if(in_array($field, $this->hiddenFields)) continue;

            switch($fieldData->type) {
                case 'int':
                    $item[$field] = (int)$this->{$field};
                    break;
                case 'float':
                case 'double':
                case 'decimal':
                    $item[$field] = (double)$this->{$field};
                    break;
                case 'tinyint':
                    $item[$field] = (bool)$this->{$field};
                    break;
                case 'varchar':
                case 'text':
                case 'time':
                    $item[$field] = (string)$this->{$field};
                    break;
                case 'datetime':
                    if($this->{$field} != null && $this->{$field} != "0000-00-00 00:00:00") {
                        $item[$field] = (string)strtotime($this->{$field});
                        try {
                            $foo = new DateTime($this->{$field}, new DateTimeZone("Europe/Copenhagen"));
                            $foo->setTimeZone(new DateTimeZone("UTC"));
                            $item[$field] = $foo->format('c');
                        } catch(\Exception $e) {
                            Data::debug(get_class($this), "ERROR", $e->getMessage());
                        }
                    } else $item[$field] = null;
                    break;
                default:
                    $item[$field] = $this->{$field};
            }
        }

        // Relations
        /** @var RelationDef[] $relations */
        $relations = ModelDefinitionCache::getRelations($this->getSimpleName());
        foreach($relations as $relation) {
            $field = $relation->getSimpleName();
            switch($relation->getType()) {
                case RelationDef::HasOne:
                    if(isset($this->{$field}) && $this->{$field}->exists())
                        $item[$field] = $this->{$field}->toArray();
                    break;
                case RelationDef::HasMany:
                    $field = plural($field);
                    if(isset($this->{$field}) && $this->{$field}->exists())
                        $item[$field] = $this->{$field}->allToArray();
                    break;
            }
        }


        return $item;
    }

    // </editor-fold>

    // <editor-fold desc="Entity as an array">

    public function count() {
        return isset($this->all) ? count($this->all) : ($this->exists() ? 1 : 0);
    }

    public function add($item) {
        if(!isset($this->all)) $this->all = [];
        $this->all[] = $item;
        $this->idMap = null;
    }

    public function remove($item) {
        if(!isset($this->all)) $this->all = [];
        if(($key = array_search($item, $this->all)) !== false) {
            unset($this->all[$key]);
        }
        $this->idMap = null;
    }

    public function removeById($id) {
        $item = $this->getById($id);
        if($item) $this->remove($item);
    }

    private $idMap = null; // Initialized when needed
    private function initIdMap() {
        $this->idMap = [];
        foreach($this as $item) $this->idMap[$item->id] = $item;
    }
    public function getById($id) {
        if(is_null($this->idMap)) $this->initIdMap();
        return isset($this->idMap[$id]) ? $this->idMap[$id] : null;
    }

    public function hasId($id) {
        if(is_null($this->idMap)) $this->initIdMap();
        return isset($this->idMap[$id]);
    }

    public function clear() {
        $this->all = [];
    }

    /**
     * @return ArrayIterator|Traversable|Entity[]
     */
    public function getIterator() {
        return new ArrayIterator(isset($this->all) ? $this->all : []);
    }

    // </editor-fold>


    public function getTableFields() {
        $namespace = explode('\\', get_class($this));
        $entityName = end($namespace);
        $fields = ModelDefinitionCache::getFields($entityName);
        return $fields;
    }

    public function resetStoredFields() {
        foreach($this->getTableFields() as $field)
            $this->stored[$field] = $this->{$field};
    }

}