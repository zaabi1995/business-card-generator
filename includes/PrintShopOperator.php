<?php
/**
 * PrintShopOperator, multi-operator login model for a print shop.
 *
 * Backed by `print_shop_operators` (migration 106). Each row is one
 * person who can sign into a print-shop account by phone or email
 * OTP. Phone and email are unique within a shop (an individual can
 * be an operator at multiple shops).
 */
class PrintShopOperator {

    public static function getById(string $id): ?array {
        $db = Database::getInstance();
        try {
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare("SELECT * FROM print_shop_operators WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('PrintShopOperator::getById ' . $e->getMessage());
            return null;
        }
    }

    public static function getByPhone(string $phone): ?array {
        $db = Database::getInstance();
        try {
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare(
                "SELECT * FROM print_shop_operators
                 WHERE phone = ? AND status = 'active' LIMIT 1"
            );
            $stmt->execute([$phone]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('PrintShopOperator::getByPhone ' . $e->getMessage());
            return null;
        }
    }

    public static function getByEmail(string $email): ?array {
        $db = Database::getInstance();
        try {
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare(
                "SELECT * FROM print_shop_operators
                 WHERE email = ? AND status = 'active' LIMIT 1"
            );
            $stmt->execute([strtolower(trim($email))]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('PrintShopOperator::getByEmail ' . $e->getMessage());
            return null;
        }
    }

    public static function listForShop(int $printShopId): array {
        $db = Database::getInstance();
        try {
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare(
                "SELECT * FROM print_shop_operators
                 WHERE print_shop_id = ?
                 ORDER BY status ASC, name ASC"
            );
            $stmt->execute([$printShopId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log('PrintShopOperator::listForShop ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Upsert an operator. Returns ['success' => bool, 'id' => string, 'error' => ?].
     * Phone is normalised to E.164; email is lowercased.
     */
    public static function save(int $printShopId, array $data, ?string $existingId = null): array {
        $name = trim($data['name'] ?? '');
        if ($name === '') {
            return ['success' => false, 'error' => 'name_required'];
        }

        $phone = isset($data['phone']) ? Phone::normalize($data['phone']) : null;
        $email = isset($data['email']) ? strtolower(trim($data['email'])) : null;
        if ($email === '') $email = null;
        $status = ($data['status'] ?? 'active') === 'disabled' ? 'disabled' : 'active';

        if ($phone === null && $email === null) {
            return ['success' => false, 'error' => 'phone_or_email_required'];
        }
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'invalid_email'];
        }

        $db = Database::getInstance();
        try {
            $pdo = $db->getConnection();
            if ($existingId) {
                $stmt = $pdo->prepare(
                    "UPDATE print_shop_operators
                     SET name = ?, phone = ?, email = ?, status = ?
                     WHERE id = ? AND print_shop_id = ?"
                );
                $stmt->execute([$name, $phone, $email, $status, $existingId, $printShopId]);
                return ['success' => true, 'id' => $existingId];
            }

            $id = function_exists('generateUUID') ? generateUUID() : bin2hex(random_bytes(16));
            $stmt = $pdo->prepare(
                "INSERT INTO print_shop_operators
                 (id, print_shop_id, name, phone, email, status)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$id, $printShopId, $name, $phone, $email, $status]);
            return ['success' => true, 'id' => $id];
        } catch (PDOException $e) {
            // Duplicate key from the per-shop unique indexes
            if ($e->getCode() === '23000') {
                return ['success' => false, 'error' => 'duplicate_phone_or_email'];
            }
            error_log('PrintShopOperator::save ' . $e->getMessage());
            return ['success' => false, 'error' => 'save_failed'];
        }
    }

    public static function disable(string $id, int $printShopId): bool {
        $db = Database::getInstance();
        try {
            $pdo = $db->getConnection();
            $stmt = $pdo->prepare(
                "UPDATE print_shop_operators
                 SET status = 'disabled'
                 WHERE id = ? AND print_shop_id = ?"
            );
            $stmt->execute([$id, $printShopId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log('PrintShopOperator::disable ' . $e->getMessage());
            return false;
        }
    }

    public static function touchLogin(string $id): void {
        $db = Database::getInstance();
        try {
            $pdo = $db->getConnection();
            $pdo->prepare("UPDATE print_shop_operators SET last_login_at = NOW() WHERE id = ?")
                ->execute([$id]);
        } catch (PDOException $e) {
            error_log('PrintShopOperator::touchLogin ' . $e->getMessage());
        }
    }
}
