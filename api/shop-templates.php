<?php
/**
 * API: Shop Templates
 * Handles save, delete, get for print shop card templates
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/PrintShop.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: list templates for a shop (public) ──
if ($method === 'GET') {
    $shopId = (int)($_GET['shop_id'] ?? 0);
    if (!$shopId) {
        echo json_encode(['success' => false, 'error' => 'Missing shop_id']);
        exit;
    }
    $db = Database::getInstance();
    $templates = $db->fetchAll(
        "SELECT id, name, description, thumbnail_path, field_definitions, sort_order
         FROM shop_templates
         WHERE print_shop_id = :shop AND is_active = 1
         ORDER BY sort_order ASC, created_at DESC",
        ['shop' => $shopId]
    );
    // Parse field_definitions
    foreach ($templates as &$t) {
        $t['field_definitions'] = json_decode($t['field_definitions'] ?? '[]', true) ?: [];
    }
    echo json_encode(['success' => true, 'templates' => $templates]);
    exit;
}

// ── POST: save or delete ──
if ($method !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// CSRF for browser requests
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

Auth::requireLogin();
$user = Auth::getCurrentUser();
if ($user['role'] !== 'print_shop' && $user['role'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$action = $_POST['action'] ?? '';
$db = Database::getInstance();

// ── SAVE template ──
if ($action === 'save') {
    $shopId = (int)($_POST['shop_id'] ?? 0);
    $templateId = trim($_POST['template_id'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $canvasJson = $_POST['canvas_json'] ?? '';
    $fieldDefs = $_POST['field_definitions'] ?? '[]';
    $canvasWidth = (int)($_POST['canvas_width'] ?? 900);
    $canvasHeight = (int)($_POST['canvas_height'] ?? 514);

    // The editor posts the canvas size and nothing ever stored it: these two
    // variables were read and then dropped, shop_templates has no width or
    // height column, and fabric's toJSON() does not include the canvas
    // dimensions. The editor's load path already reads data.width and
    // data.height off canvas_json, so only the writer was missing. Without it a
    // template saved as Square (900x900) or Large (1050x600) reopened at the
    // 900x514 default, moving every object that sat below the shorter canvas
    // straight off the card. Verified: "Gauntlet Square Size Test" saved at
    // 900x900 and reopened at 900x514.
    if ($canvasJson !== '' && $canvasWidth > 0 && $canvasHeight > 0) {
        $decoded = json_decode($canvasJson, true);
        if (is_array($decoded)) {
            $decoded['width'] = $canvasWidth;
            $decoded['height'] = $canvasHeight;
            $reencoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($reencoded !== false) $canvasJson = $reencoded;
        }
    }

    if (!$shopId || !$name) {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
        exit;
    }

    // Verify shop ownership
    $shop = PrintShop::getById($shopId);
    if (!$shop) {
        echo json_encode(['success' => false, 'error' => 'Shop not found']);
        exit;
    }
    if ($user['role'] !== 'super_admin') {
        $myShop = PrintShop::getByUserId($user['id']);
        if (!$myShop || $myShop['id'] !== $shopId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            exit;
        }
    }

    // Upload directory
    $uploadDir = BASE_DIR . '/uploads/print_shops/' . $shopId . '/templates';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $backgroundPath = null;
    $thumbnailPath = null;

    // Handle background image upload
    if (isset($_FILES['background_image']) && $_FILES['background_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['background_image'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mimeType, $allowedTypes)) {
            echo json_encode(['success' => false, 'error' => 'Invalid image type']);
            exit;
        }
        if ($file['size'] > 10 * 1024 * 1024) {
            echo json_encode(['success' => false, 'error' => 'Image too large (max 10MB)']);
            exit;
        }

        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $ext = $extensions[$mimeType] ?? 'png';
        $filename = 'bg_' . uniqid() . '.' . $ext;

        if (move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $filename)) {
            $backgroundPath = '/uploads/print_shops/' . $shopId . '/templates/' . $filename;
        }
    }

    // Generate thumbnail from canvas if we have background
    // (Thumbnail generation is deferred to client-side canvas.toDataURL sent separately)
    // Check for base64 thumbnail in POST
    if (!empty($_POST['thumbnail_data'])) {
        $thumbData = $_POST['thumbnail_data'];
        if (preg_match('/^data:image\/(png|jpeg|jpg);base64,/', $thumbData, $m)) {
            $thumbBase64 = preg_replace('/^data:image\/[a-z]+;base64,/', '', $thumbData);
            $thumbBytes = base64_decode($thumbBase64);
            if ($thumbBytes !== false) {
                $thumbFilename = 'thumb_' . uniqid() . '.png';
                file_put_contents($uploadDir . '/' . $thumbFilename, $thumbBytes);
                $thumbnailPath = '/uploads/print_shops/' . $shopId . '/templates/' . $thumbFilename;
            }
        }
    }

    $now = date('Y-m-d H:i:s');

    if ($templateId) {
        // Update existing
        $existing = $db->fetchOne(
            "SELECT * FROM shop_templates WHERE id = :id AND print_shop_id = :shop",
            ['id' => $templateId, 'shop' => $shopId]
        );
        if (!$existing) {
            echo json_encode(['success' => false, 'error' => 'Template not found']);
            exit;
        }
        // If no new background uploaded, keep existing
        if (!$backgroundPath) $backgroundPath = $existing['background_path'];
        if (!$thumbnailPath) $thumbnailPath = $existing['thumbnail_path'];

        $db->update('shop_templates',
            [
                'name' => $name,
                'description' => $description ?: null,
                'background_path' => $backgroundPath,
                'thumbnail_path' => $thumbnailPath,
                'canvas_json' => $canvasJson,
                'field_definitions' => $fieldDefs,
                'updated_at' => $now,
            ],
            'id = :id',
            ['id' => $templateId]
        );
        echo json_encode(['success' => true, 'template_id' => $templateId, 'action' => 'updated']);
    } else {
        // Insert new
        $newId = generateUUID();
        $db->insert('shop_templates', [
            'id' => $newId,
            'print_shop_id' => $shopId,
            'name' => $name,
            'description' => $description ?: null,
            'background_path' => $backgroundPath,
            'thumbnail_path' => $thumbnailPath,
            'canvas_json' => $canvasJson,
            'field_definitions' => $fieldDefs,
            'is_active' => 1,
            'sort_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        echo json_encode(['success' => true, 'template_id' => $newId, 'action' => 'created']);
    }
    exit;
}

// ── SUBMIT REQUEST (customer ordering) ──
if ($action === 'submit_request') {
    $templateId = trim($_POST['template_id'] ?? '');
    $customerName = trim($_POST['customer_name'] ?? '');
    $customerPhone = trim($_POST['customer_phone'] ?? '');
    $customerEmail = trim($_POST['customer_email'] ?? '');
    $fieldValues = $_POST['field_values'] ?? '{}';
    $canvasSnapshot = $_POST['canvas_snapshot'] ?? '';
    $quantity = max(1, (int)($_POST['quantity'] ?? 100));
    $notes = trim($_POST['notes'] ?? '');

    if (!$templateId || !$customerName || !$customerPhone) {
        echo json_encode(['success' => false, 'error' => 'Please fill in your name and phone number']);
        exit;
    }

    // Find template
    $template = $db->fetchOne("SELECT * FROM shop_templates WHERE id = :id AND is_active = 1", ['id' => $templateId]);
    if (!$template) {
        echo json_encode(['success' => false, 'error' => 'Template not found']);
        exit;
    }

    // Save preview image if provided
    $previewPath = null;
    if (!empty($_POST['preview_data'])) {
        $previewData = $_POST['preview_data'];
        if (preg_match('/^data:image\/[a-z]+;base64,/', $previewData)) {
            $previewBase64 = preg_replace('/^data:image\/[a-z]+;base64,/', '', $previewData);
            $previewBytes = base64_decode($previewBase64);
            if ($previewBytes !== false) {
                $previewDir = BASE_DIR . '/uploads/print_shops/' . $template['print_shop_id'] . '/requests';
                if (!is_dir($previewDir)) mkdir($previewDir, 0755, true);
                $previewFilename = 'preview_' . uniqid() . '.png';
                file_put_contents($previewDir . '/' . $previewFilename, $previewBytes);
                $previewPath = '/uploads/print_shops/' . $template['print_shop_id'] . '/requests/' . $previewFilename;
            }
        }
    }

    $requestId = generateUUID();
    $db->insert('shop_template_requests', [
        'id' => $requestId,
        'template_id' => $templateId,
        'print_shop_id' => $template['print_shop_id'],
        'customer_name' => $customerName,
        'customer_phone' => $customerPhone,
        'customer_email' => $customerEmail ?: null,
        'field_values' => $fieldValues,
        'canvas_snapshot' => $canvasSnapshot ?: null,
        'preview_image_path' => $previewPath,
        'quantity' => $quantity,
        'notes' => $notes ?: null,
        'status' => 'pending',
        'created_at' => dbNow(),
        'updated_at' => dbNow(),
    ]);

    // Notify BHD via WhatsApp if configured
    try {
        $shop = PrintShop::getById($template['print_shop_id']);
        if ($shop && !empty($shop['whatsapp'])) {
            require_once INCLUDES_DIR . '/WhatsApp.php';
            $msg = "New card request from Cardify!\n\n";
            $msg .= "Customer: {$customerName}\n";
            $msg .= "Phone: {$customerPhone}\n";
            if ($customerEmail) $msg .= "Email: {$customerEmail}\n";
            $msg .= "Template: " . $template['name'] . "\n";
            $msg .= "Quantity: {$quantity} cards\n";
            if ($notes) $msg .= "Notes: {$notes}\n";
            $msg .= "\nView request: " . getBaseUrl() . "printshop/template-requests.php";
            WhatsApp::send($shop['whatsapp'], $msg);
        }
    } catch (Exception $e) {
        // Notification failure is non-critical
    }

    echo json_encode([
        'success' => true,
        'request_id' => $requestId,
        'shop_whatsapp' => $template['print_shop_id'] ? (PrintShop::getById($template['print_shop_id'])['whatsapp'] ?? null) : null,
    ]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);
