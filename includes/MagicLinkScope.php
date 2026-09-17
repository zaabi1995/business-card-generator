<?php
/**
 * MagicLinkScope: what a session opened from an approval link may reach.
 *
 * AdminApprovalToken::startAdminSession() sets user_role = admin so the flow
 * pages recognise the approver. Most admin pages only ask for that role, so the
 * same session could open the departments screen, change another division's
 * approver, approve other divisions' requests and read every employee.
 *
 * This runs on every web request (functions.php). A link session reaches only
 * the flow pages below, and the card-generation endpoints only for the one
 * employee that link approved. A real login clears the mark (clear()).
 */
class MagicLinkScope
{
    /** Admin and API pages a link session may open. */
    private const FLOW_PAGES = [
        'one-tap-approve', 'approve-request', 'card-jobs', 'card-job-file',
        'sign-delivery', 'batch_generate', 'auto_generate', 'send_card_email',
        'logout',
    ];

    /** Pages that act on one employee: allowed only for the approved one. */
    private const EMPLOYEE_PAGES = [
        'batch_generate', 'auto_generate', 'send_card_email',
        'save_card_image', 'save_card_both', 'log_generation',
    ];

    public static function clear(): void
    {
        unset($_SESSION['magic_link'], $_SESSION['magic_link_email'],
              $_SESSION['magic_link_request'], $_SESSION['magic_link_employee'],
              $_SESSION['magic_link_employee_email']);
    }

    public static function enforce(): void
    {
        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE
            || empty($_SESSION['magic_link'])) {
            return;
        }
        $path = strtolower((string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''));
        $page = preg_replace('/\.php$/', '', basename($path));
        $isAdmin = strpos($path, '/admin/') !== false || strpos($path, '/api/') !== false;

        if ($isAdmin && !in_array($page, self::FLOW_PAGES, true)
            && !in_array($page, self::EMPLOYEE_PAGES, true)) {
            self::deny();
        }
        if (in_array($page, self::EMPLOYEE_PAGES, true)) {
            $input = [];
            if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json') !== false) {
                $input = json_decode((string)file_get_contents('php://input'), true) ?: [];
            }
            $emp   = (string)($_REQUEST['employee_id'] ?? $input['employee_id'] ?? '');
            $email = strtolower(trim((string)($input['employee_email'] ?? '')));
            $mine  = (string)($_SESSION['magic_link_employee'] ?? '');
            $mineEmail = strtolower((string)($_SESSION['magic_link_employee_email'] ?? ''));
            if ($mine === '' || $emp !== $mine) {
                self::deny();
            }
            if ($email !== '' && $email !== $mineEmail) {
                self::deny();
            }
        }
    }

    private static function deny(): void
    {
        http_response_code(403);
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        if (stripos($accept, 'json') !== false || $_SERVER['REQUEST_METHOD'] !== 'GET') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Not available from an approval link']);
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta charset="utf-8"><title>Not available</title>'
               . '<p style="font-family:sans-serif;margin:40px">This page is not available from an approval link. '
               . 'Please sign in to Cardify to open it.</p>';
        }
        exit;
    }
}
