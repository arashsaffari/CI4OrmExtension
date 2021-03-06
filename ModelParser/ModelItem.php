<?php namespace OrmExtension\ModelParser;
/**
 * Created by PhpStorm.
 * User: martin
 * Date: 25/11/2018
 * Time: 12.18
 *
 * @property string $path
 * @property string $name
 * @property PropertyItem[] $properties
 */
class ModelItem {

    public $properties = [];

    /**
     * @param $path
     * @return ModelItem
     * @throws \ReflectionException
     */
    public static function parse($path) {
        $item = new ModelItem();
        $item->path = $path;

        $rc = new \ReflectionClass("\App\Entities\\{$path}");
        $item->name = substr($rc->getName(), strrpos($rc->getName(), '\\') + 1);

        $comments = $rc->getDocComment();
        $lines = explode("\n", $comments);
        $isMany = false;
        foreach($lines as $line) {
            if(strpos($line, 'Many') !== false) $isMany = true;
            if(strpos($line, 'OTF') !== false) $isMany = false;
            $property = PropertyItem::parse($line, $isMany);
            if($property)
                $item->properties[] = $property;
        }

        // Append static properties
        $item->properties[] = new PropertyItem('id', 'number', true, false);
        $item->properties[] = new PropertyItem('created', 'string', true, false);
        $item->properties[] = new PropertyItem('updated', 'string', true, false);
        $item->properties[] = new PropertyItem('created_by_id', 'number', true, false);
        $item->properties[] = new PropertyItem('created_by', 'User', false, false);
        $item->properties[] = new PropertyItem('updated_by_id', 'number', true, false);
        $item->properties[] = new PropertyItem('updated_by', 'User', false, false);

        return $item;
    }

    public function toSwagger() {
        $item = [
            'title'         => $this->name,
            'type'          => 'object',
            'properties'    => []
        ];
        foreach($this->properties as $property) {
            $item['properties'][$property->name] = $property->toSwagger();
        }
        return $item;
    }

}