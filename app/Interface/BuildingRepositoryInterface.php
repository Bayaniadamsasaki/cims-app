<?php

namespace App\Interface;

interface BuildingRepositoryInterface
{
    public function paginate(int $perPage = 15, array $filters = []);
    public function all();
    public function find(int $id);
    public function create(array $data);
    public function update(int $id, array $data);
    public function delete(int $id);
}
