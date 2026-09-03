<?php
/**
 * Print partner <-> client company attachment.
 *
 * Regular print shops operate only the company tenants they created
 * or were attached to. They do not browse every Cardify tenant.
 * The BHD internal-provider shop keeps its existing cross-tenant
 * list for marketplace fulfillment.
 *
 * Policy methods are pure (no Database) so tests can load this file
 * without config.php.
 */
class PrintShopClients
{
    /**
     * Company-admin access for a print partner.
     *
     * @param array $shop Shop row (id, status, is_internal_provider)
     * @param string $companyId Target tenant
     * @param array $attachedCompanyIds Company ids attached to this shop
     */
    public static function canAccessCompanyAdmin(array $shop, string $companyId, array $attachedCompanyIds): bool
    {
        if ($companyId === '') {
            return false;
        }
        if (!self::canOperateClientTenants($shop)) {
            return false;
        }
        if (!empty($shop['is_internal_provider'])) {
            return true;
        }
        return in_array($companyId, $attachedCompanyIds, true);
    }

    public static function listsAllCompanies(array $shop): bool
    {
        return !empty($shop['is_internal_provider']);
    }

    public static function canOperateClientTenants(array $shop): bool
    {
        if (empty($shop) || empty($shop['id'])) {
            return false;
        }
        $status = (string) ($shop['status'] ?? '');
        return $status !== 'suspended';
    }

    public static function isPartnerRole(?string $role): bool
    {
        return $role === 'print_shop' || $role === 'print_shop_operator';
    }

    /**
     * Session helper used by company_admin.php, requireAdmin(), and
     * adminHeader(). False when the caller is not a print partner or
     * the current shop cannot manage that tenant.
     */
    public static function currentSessionCanAccessCompanyAdmin(?string $companyId): bool
    {
        if (!$companyId) {
            return false;
        }
        $role = $_SESSION['user_role'] ?? '';
        if (!self::isPartnerRole($role)) {
            return false;
        }
        $shop = self::currentShop();
        if (!$shop) {
            return false;
        }
        if (!empty($shop['is_internal_provider'])) {
            return self::canOperateClientTenants($shop);
        }
        return self::canAccessCompanyAdmin($shop, $companyId, self::listAttachedCompanyIds((int) $shop['id']));
    }

    public static function isAttached(int $shopId, string $companyId): bool
    {
        if ($shopId < 1 || $companyId === '') {
            return false;
        }
        return in_array($companyId, self::listAttachedCompanyIds($shopId), true);
    }

    /**
     * @return string[]
     */
    public static function listAttachedCompanyIds(int $shopId): array
    {
        if ($shopId < 1 || !self::dbReady()) {
            return [];
        }
        try {
            $pdo = Database::getInstance()->getConnection();
            $stmt = $pdo->prepare(
                "SELECT company_id FROM print_shop_companies WHERE print_shop_id = ?"
            );
            $stmt->execute([$shopId]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
            return array_values(array_filter(array_map('strval', $ids)));
        } catch (Throwable $e) {
            error_log('PrintShopClients::listAttachedCompanyIds: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Attach a company to a shop. Idempotent.
     *
     * @param string $source created | attached
     */
    public static function attach(int $shopId, string $companyId, string $source = 'created'): bool
    {
        if ($shopId < 1 || $companyId === '' || !self::dbReady()) {
            return false;
        }
        $source = $source === 'attached' ? 'attached' : 'created';
        try {
            $pdo = Database::getInstance()->getConnection();
            $id = function_exists('generateUUID') ? generateUUID() : bin2hex(random_bytes(16));
            $stmt = $pdo->prepare(
                "INSERT INTO print_shop_companies (id, print_shop_id, company_id, source)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE source = source"
            );
            $stmt->execute([$id, $shopId, $companyId, $source]);
            return true;
        } catch (Throwable $e) {
            error_log('PrintShopClients::attach: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a client company tenant and attach it to the shop.
     * Reuses DatabaseAdapter::createCompany so the tenant is a normal
     * Cardify company, not a parallel product.
     *
     * @param array $shop Shop row
     * @param array $input name, slug?, country?
     * @return array{success:bool,error?:string,company?:array}
     */
    public static function createClientCompany(array $shop, array $input): array
    {
        $t = static function (string $key, string $fallback) {
            if (function_exists('t')) {
                $translated = t($key);
                if ($translated !== $key) {
                    return $translated;
                }
            }
            return $fallback;
        };
        if (!self::canOperateClientTenants($shop)) {
            return ['success' => false, 'error' => $t('printshopinternal.create_forbidden', 'This print shop cannot operate client companies.')];
        }
        $name = trim((string) ($input['name'] ?? ''));
        $slug = strtolower(trim((string) ($input['slug'] ?? '')));
        $country = strtoupper(trim((string) ($input['country'] ?? '')));
        if ($name === '') {
            return ['success' => false, 'error' => $t('printshopinternal.err_company_name', 'Company name is required.')];
        }
        if ($slug !== '' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            return ['success' => false, 'error' => $t('printshopinternal.err_company_slug', 'Company URL may contain lowercase letters, numbers, and hyphens only.')];
        }
        if ($country !== '' && !preg_match('/^[A-Z]{2}$/', $country)) {
            return ['success' => false, 'error' => $t('printshopinternal.err_company_country', 'Country must be a 2-letter code.')];
        }

        if (!function_exists('createCompany')) {
            return ['success' => false, 'error' => $t('printshopinternal.create_failed', 'Could not create the client company.')];
        }

        $adminEmail = trim((string) ($shop['email'] ?? ''));
        if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $adminEmail = 'shop-' . (int) $shop['id'] . '@invalid.cardify.om';
        }

        $result = createCompany($name, $adminEmail, bin2hex(random_bytes(16)), null, $slug !== '' ? $slug : null);
        if (empty($result['success']) || empty($result['company']['id'])) {
            return [
                'success' => false,
                'error' => (string) ($result['error'] ?? $t('printshopinternal.create_failed', 'Could not create the client company.')),
            ];
        }

        $company = $result['company'];
        if (!self::attach((int) $shop['id'], $company['id'], 'created')) {
            return ['success' => false, 'error' => $t('printshopinternal.err_attach', 'Company created but could not attach it to this print shop.')];
        }

        if ($country !== '' && self::dbReady()) {
            try {
                $db = Database::getInstance();
                if (method_exists($db, 'columnExists') && $db->columnExists('companies', 'country')) {
                    $db->update('companies', ['country' => $country], 'id = :id', ['id' => $company['id']]);
                    $company['country'] = $country;
                }
            } catch (Throwable $e) {
                error_log('PrintShopClients::createClientCompany country: ' . $e->getMessage());
            }
        }

        return ['success' => true, 'company' => $company];
    }

    private static function currentShop(): ?array
    {
        if (!class_exists('PrintShopAuth')) {
            $path = __DIR__ . '/PrintShopAuth.php';
            if (is_file($path)) {
                require_once $path;
            }
        }
        if (!class_exists('PrintShopAuth')) {
            return null;
        }
        return PrintShopAuth::currentShop();
    }

    private static function dbReady(): bool
    {
        return class_exists('Database') && method_exists('Database', 'getInstance');
    }
}
