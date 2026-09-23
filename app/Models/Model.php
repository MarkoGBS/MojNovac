<?php

namespace App\Models;

use PDO;

abstract class Model
{
    public function __construct(protected PDO $db)
    {
    }

    protected function query(string $sql, array $params = []): array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    protected function paginate(string $sql, string $countSql, array $params = [], int $page = 1, int $perPage = 10): array
    {
        $count = $this->db->prepare($countSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $perPage = max(1, min(100, $perPage));
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));

        $statement = $this->db->prepare($sql . ' LIMIT ? OFFSET ?');
        foreach (array_values($params) as $index => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $statement->bindValue($index + 1, $value, $type);
        }
        $statement->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
        $statement->bindValue(count($params) + 2, ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => $statement->fetchAll(),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'per_page' => $perPage,
        ];
    }
}
