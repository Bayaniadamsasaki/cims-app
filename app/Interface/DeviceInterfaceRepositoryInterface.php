<?php

namespace App\Interface;

interface DeviceInterfaceRepositoryInterface
{
    public function paginateByDevice(int $deviceId, int $perPage = 15, array $filters = []);
    public function findByDevice(int $deviceId, int $id);
    public function create(array $data);
    public function update(int $id, array $data);
    public function delete(int $id);
}
