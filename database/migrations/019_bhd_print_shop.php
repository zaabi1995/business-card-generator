<?php
/**
 * Migration 019: Add BHD Printing & Designing print shop
 * Seeds the print_shops table with BHD Printing & Designing
 */

function migration_019_bhd_print_shop($pdo) {
    $errors = [];
    
    // Check if print_shops table exists
    try {
        $stmt = $pdo->query("SELECT 1 FROM print_shops LIMIT 1");
    } catch (PDOException $e) {
        return ['success' => false, 'errors' => ['print_shops table does not exist. Run earlier migrations first.']];
    }
    
    // Check if BHD Printing already exists
    try {
        $stmt = $pdo->prepare("SELECT id FROM print_shops WHERE slug = 'bhd-printing-designing' OR email = 'print@bhd.om'");
        $stmt->execute();
        if ($stmt->fetch()) {
            return ['success' => true, 'message' => 'BHD Printing & Designing already exists'];
        }
    } catch (PDOException $e) {
        // Continue with creation
    }
    
    // Insert BHD Printing & Designing
    try {
        $stmt = $pdo->prepare("
            INSERT INTO print_shops (
                name, slug, email, phone, website, description,
                address, city, state, country, postal_code,
                currency, status, verified, featured, rating, total_orders,
                min_order_quantity, turnaround_days, express_available, express_days,
                created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                NOW(), NOW()
            )
        ");
        
        $stmt->execute([
            'BHD Printing & Designing',
            'bhd-printing-designing',
            'print@bhd.om',
            '+968 24 123 456',
            'https://bhd.om',
            'Professional business card printing services in Oman. High-quality printing with fast turnaround times. We offer standard, premium, and luxury card options with various finishes including matte, gloss, and spot UV.',
            'Way 2834, Building 123',
            'Muscat',
            'Muscat Governorate',
            'Oman',
            '100',
            'OMR',
            'active',
            1, // verified
            1, // featured
            5.0, // rating
            0, // total_orders
            50, // min_order_quantity
            3, // turnaround_days
            1, // express_available
            1, // express_days
        ]);
        
        $shopId = $pdo->lastInsertId();
        
        // Set pricing JSON
        $pricing = json_encode([
            'standard' => [
                'name' => 'Standard Business Cards',
                'description' => 'High-quality 300gsm matte finish cards',
                'price_per_100' => 5.00,
                'min_quantity' => 100
            ],
            'premium' => [
                'name' => 'Premium Business Cards', 
                'description' => '400gsm cards with gloss lamination',
                'price_per_100' => 8.00,
                'min_quantity' => 100
            ],
            'luxury' => [
                'name' => 'Luxury Business Cards',
                'description' => '500gsm cards with spot UV and embossing',
                'price_per_100' => 15.00,
                'min_quantity' => 50
            ]
        ]);
        
        // Set services JSON
        $services = json_encode([
            'business_cards' => true,
            'letterheads' => true,
            'envelopes' => true,
            'flyers' => true
        ]);
        
        // Set paper types JSON
        $paperTypes = json_encode([
            '300gsm_matte' => 'Standard 300gsm Matte',
            '400gsm_gloss' => 'Premium 400gsm Gloss',
            '500gsm_textured' => 'Luxury 500gsm Textured'
        ]);
        
        // Set finishes JSON
        $finishes = json_encode([
            'matte' => 'Matte Lamination',
            'gloss' => 'Gloss Lamination',
            'spot_uv' => 'Spot UV',
            'embossing' => 'Embossing',
            'foil' => 'Gold/Silver Foil'
        ]);
        
        // Update with JSON fields
        $updateStmt = $pdo->prepare("
            UPDATE print_shops 
            SET pricing = ?, services = ?, paper_types = ?, finishes = ?
            WHERE id = ?
        ");
        $updateStmt->execute([$pricing, $services, $paperTypes, $finishes, $shopId]);
        
        return ['success' => true, 'message' => 'Added BHD Printing & Designing print shop'];
        
    } catch (PDOException $e) {
        return ['success' => false, 'errors' => ['Failed to create print shop: ' . $e->getMessage()]];
    }
}
