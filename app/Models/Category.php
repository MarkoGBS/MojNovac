<?php

namespace App\Models;

use DomainException;
use PDOException;

final class Category extends Model
{
    public function page(int $page = 1): array
    {
        return $this->paginate(
            'SELECT * FROM categories ORDER BY name, id',
            'SELECT COUNT(*) FROM categories',
            [],
            $page
        );
    }

    public function active(?string $type = null): array
    {
        if ($type !== null) {
            return $this->query('SELECT * FROM categories WHERE active = 1 AND type = ? ORDER BY name', [$type]);
        }

        return $this->query('SELECT * FROM categories WHERE active = 1 ORDER BY name');
    }

    public function create(string $name, string $type): void
    {
        $name = trim($name);
        if ($name === '' || !in_array($type, ['income', 'expense'], true)) {
            throw new DomainException('Podaci kategorije nisu ispravni.');
        }

        try {
            $this->db->prepare('INSERT INTO categories (name, type) VALUES (?, ?)')->execute([$name, $type]);
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062) {
                throw $e;
            }
            throw new DomainException('Kategorija sa tim nazivom već postoji.', 0, $e);
        }
    }

    public function toggle(int $id): void
    {
        $this->db->prepare('UPDATE categories SET active = NOT active WHERE id = ?')->execute([$id]);
    }
}
