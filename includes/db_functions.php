<?php
/**
 * Database helper functions — dunne wrappers rond PDO.
 */

if (!defined('DKG_BOOT')) { http_response_code(403); exit('Forbidden'); }

function db_all(string $sql, array $params = []): array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function db_one(string $sql, array $params = []): ?array {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function db_value(string $sql, array $params = []) {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $v = $stmt->fetchColumn();
    return $v === false ? null : $v;
}

function db_exec(string $sql, array $params = []): int {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

function db_insert(string $table, array $data): int {
    $cols  = array_keys($data);
    $place = array_map(fn($c) => ':' . $c, $cols);
    $sql   = sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        $table,
        implode(',', $cols),
        implode(',', $place)
    );
    $stmt = db()->prepare($sql);
    $stmt->execute(array_combine($place, array_values($data)));
    return (int)db()->lastInsertId();
}

function db_update(string $table, array $data, string $where, array $whereParams = []): int {
    $set = [];
    $params = [];
    foreach ($data as $col => $val) {
        $set[] = "$col = :set_$col";
        $params[":set_$col"] = $val;
    }
    $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $set), $where);
    $stmt = db()->prepare($sql);
    $stmt->execute($params + $whereParams);
    return $stmt->rowCount();
}

function db_transaction(callable $fn) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
