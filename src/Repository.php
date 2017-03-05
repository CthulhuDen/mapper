<?php

namespace Tarantool\Mapper;

use Exception;
use SplObjectStorage;

class Repository
{
    private $space;
    private $persisted = [];
    private $original = [];
    private $keys;

    private $cache = [];
    private $results = [];

    public function __construct(Space $space)
    {
        $this->space = $space;
        $this->keys = new SplObjectStorage;
    }

    public function create($data)
    {
        $instance = (object) [];
        foreach($this->space->getFormat() as $row) {
            if(array_key_exists($row['name'], $data)) {
                $instance->{$row['name']} = $data[$row['name']];
            }
        }
        
        // validate instance key
        $key = $this->space->getInstanceKey($instance);

        $this->keys[$instance] = $key;
        return $instance;
    }

    public function findOne($params = [])
    {
        return $this->find($params, true);
    }

    public function find($params = [], $one = false)
    {
        $cacheIndex = array_search([$params, $one], $this->cache);
        if($cacheIndex !== false) {
            return $this->results[$cacheIndex];
        }

        if(!is_array($params)) {
            $params = [$params];
        }
        if(count($params) == 1 && array_key_exists(0, $params)) {
            $primary = $this->space->getPrimaryIndex();
            if(count($primary->parts) == 1) {
                $formatted = $this->space->getMapper()->getSchema()->formatValue($primary->parts[0][1], $params[0]);
                if($params[0] == $formatted) {
                    $params = [
                        $this->space->getFormat()[$primary->parts[0][0]]['name'] => $params[0]
                    ];
                }
            }
        }

        if(array_key_exists('id', $params)) {
            if(array_key_exists($params['id'], $this->persisted)) {
                $instance = $this->persisted[$params['id']];
                return $one ? $instance : [$instance];
            }
        }


        $index = $this->space->castIndex($params);
        if(is_null($index)) {
            throw new Exception("No index for params ".json_encode($params));
        }

        $cacheIndex = count($this->cache);
        $this->cache[] = [$params, $one];

        $client = $this->space->getMapper()->getClient();
        $values = $this->space->getIndexValues($index, $params);

        $data = $client->getSpace($this->space->getId())->select($values, $index)->getData();

        $result = [];
        foreach($data as $tuple) {
            $instance = $this->getInstance($tuple);
            if($one) {
                return $this->results[$cacheIndex] = $instance;
            }
            $result[] = $instance;
        }

        if($one) {
            return $this->results[$cacheIndex] = null;
        }

        return $this->results[$cacheIndex] = $result;
    }

    private function getInstance($tuple)
    {
        $key = $this->space->getTupleKey($tuple);

        if(array_key_exists($key, $this->persisted)) {
            return $this->persisted[$key];
        }

        $instance = (object) [];

        $this->original[$key] = $tuple;

        foreach($this->space->getFormat() as $index => $info) {
            $instance->{$info['name']} = array_key_exists($index, $tuple) ? $tuple[$index] : null;
        }

        $this->keys->offsetSet($instance, $key);

        return $this->persisted[$key] = $instance;
    }

    public function knows($instance)
    {
        return $this->keys->offsetExists($instance);
    }

    public function save($instance)
    {
        $tuple = [];

        foreach($this->space->getFormat() as $index => $info) {
            if(property_exists($instance, $info['name'])) {
                $instance->{$info['name']} = $this->space->getMapper()->getSchema()
                    ->formatValue($info['type'], $instance->{$info['name']});
                $tuple[$index] = $instance->{$info['name']};
            }
        }

        $key = $this->space->getInstanceKey($instance);
        $client = $this->space->getMapper()->getClient();

        if(array_key_exists($key, $this->persisted)) {
            // update
            $update = array_diff_assoc($tuple, $this->original[$key]);
            if(!count($update)) {
                return $instance;
            }

            $operations = [];
            foreach($update as $index => $value) {
                $operations[] = ['=', $index, $value];
            }

            $pk = [];
            foreach($this->space->getPrimaryIndex()->parts as $part) {
                $pk[] = $this->original[$key][$part[0]];
            }

            $client->getSpace($this->space->getId())->update($pk, $operations);
            $this->original[$key] = $tuple;

        } else {
            $client->getSpace($this->space->getId())->insert($tuple);
            $this->persisted[$key] = $instance;
            $this->original[$key] = $tuple;
        }


        $this->cache = [];
    }
}