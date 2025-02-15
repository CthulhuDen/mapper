<?php

declare(strict_types=1);

namespace Tarantool\Mapper;

use Exception;

class Pool
{
    private $description = [];
    private $mappers = [];
    private $resolvers = [];
    private $repositories = [];

    public function register($name, $handler)
    {
        if (array_key_exists($name, $this->description)) {
            throw new Exception("Mapper $name was registered");
        }

        if ($handler instanceof Mapper) {
            $this->description[$name] = $handler;
            $this->mappers[$name] = $handler;
            return;
        }

        if (!is_callable($handler)) {
            throw new Exception("Invalid $name handler");
        }

        $this->description[$name] = $handler;
        return $this;
    }

    public function registerResolver($resolver)
    {
        $this->resolvers[] = $resolver;
        return $this;
    }

    public function get($name)
    {
        return $this->getMapper($name);
    }

    public function getMapper($name)
    {
        if (array_key_exists($name, $this->mappers)) {
            return $this->mappers[$name];
        }

        if (array_key_exists($name, $this->description)) {
            return $this->mappers[$name] = call_user_func($this->description[$name]);
        }

        foreach ($this->resolvers as $resolver) {
            $mapper = call_user_func($resolver, $name);
            if ($mapper) {
                return $this->mappers[$name] = $mapper;
            }
        }

        throw new Exception("Mapper $name is not registered");
    }

    public function getMappers()
    {
        return array_values($this->mappers);
    }

    public function getRepository($space)
    {
        if (!array_key_exists($space, $this->repositories)) {
            $parts = explode('.', $space);
            if (count($parts) !== 2) {
                throw new Exception("Invalid pool space name: $space");
            }
            $this->repositories[$space] = $this->getMapper($parts[0])->getRepository($parts[1]);
        }
        return $this->repositories[$space];
    }

    public function create(string $space, $data)
    {
        return $this->getRepository($space)->create($data)->save();
    }

    public function findOne(string $space, $params = [])
    {
        return $this->getRepository($space)->findOne($params);
    }

    public function findOrCreate(string $space, $params = [])
    {
        return $this->getRepository($space)->findOrCreate($params)->save();
    }

    public function findOrFail(string $space, $params = [])
    {
        return $this->getRepository($space)->findOrFail($params);
    }

    public function find(string $space, $params = [])
    {
        return $this->getRepository($space)->find($params);
    }
}
