<?php
/**
 * Migration 028: Blog Posts and Career Listings
 * 
 * Creates tables for admin-manageable blog and careers content.
 */

function migration_028_blog_careers($pdo) {
    $results = ['success' => true, 'messages' => []];
    
    try {
        // Create blog_posts table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS blog_posts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL UNIQUE,
                excerpt TEXT NULL,
                content LONGTEXT NOT NULL,
                featured_image VARCHAR(500) NULL,
                status ENUM('draft', 'published', 'archived') DEFAULT 'draft',
                author_id VARCHAR(36) NULL,
                author_name VARCHAR(255) NULL,
                published_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                INDEX idx_status (status),
                INDEX idx_published_at (published_at),
                INDEX idx_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $results['messages'][] = "Created blog_posts table";
        
        // Create career_listings table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS career_listings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                slug VARCHAR(255) NOT NULL UNIQUE,
                department VARCHAR(100) NULL,
                location VARCHAR(255) NULL,
                employment_type ENUM('full-time', 'part-time', 'contract', 'internship', 'remote') DEFAULT 'full-time',
                description TEXT NOT NULL,
                requirements TEXT NULL,
                benefits TEXT NULL,
                salary_range VARCHAR(100) NULL,
                application_email VARCHAR(255) DEFAULT 'careers@cardify.om',
                status ENUM('open', 'closed', 'draft') DEFAULT 'draft',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                INDEX idx_status (status),
                INDEX idx_department (department),
                INDEX idx_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $results['messages'][] = "Created career_listings table";
        
        // Insert sample blog post
        $pdo->exec("
            INSERT INTO blog_posts (title, slug, excerpt, content, status, author_name, published_at)
            VALUES (
                'Welcome to Cardify',
                'welcome-to-cardify',
                'Discover the future of professional networking with digital business cards.',
                '<p>Welcome to Cardify, the modern platform for creating and sharing professional digital business cards.</p>
                <p>In today\\'s fast-paced business world, first impressions matter more than ever. Traditional paper business cards are becoming obsolete - they get lost, become outdated, and contribute to unnecessary waste.</p>
                <h2>Why Digital Business Cards?</h2>
                <ul>
                    <li><strong>Always Up-to-Date:</strong> Update your information once, and everyone has your latest details.</li>
                    <li><strong>Eco-Friendly:</strong> Reduce paper waste and your carbon footprint.</li>
                    <li><strong>Interactive:</strong> Include links, social profiles, and even videos.</li>
                    <li><strong>Analytics:</strong> Track when and where your card is scanned.</li>
                </ul>
                <p>Get started today and join thousands of professionals who have made the switch to digital.</p>',
                'published',
                'Cardify Team',
                NOW()
            )
            ON DUPLICATE KEY UPDATE title = VALUES(title)
        ");
        $results['messages'][] = "Added sample blog post";
        
        // Insert sample career listing
        $pdo->exec("
            INSERT INTO career_listings (title, slug, department, location, employment_type, description, requirements, benefits, status)
            VALUES (
                'Full Stack Developer',
                'full-stack-developer',
                'Engineering',
                'Muscat, Oman (Hybrid)',
                'full-time',
                'We are looking for a talented Full Stack Developer to join our engineering team. You will work on building and scaling our digital business card platform, creating features that help thousands of professionals connect.',
                '- 3+ years experience with PHP and JavaScript
- Strong knowledge of MySQL/PostgreSQL
- Experience with modern frontend frameworks (React, Vue, or similar)
- Familiarity with RESTful API design
- Good understanding of web security best practices
- Strong problem-solving skills
- Excellent communication in English',
                '- Competitive salary
- Flexible working hours
- Remote-friendly environment
- Health insurance
- Annual learning budget
- Team events and activities',
                'open'
            )
            ON DUPLICATE KEY UPDATE title = VALUES(title)
        ");
        $results['messages'][] = "Added sample career listing";
        
    } catch (PDOException $e) {
        $results['success'] = false;
        $results['messages'][] = "Error: " . $e->getMessage();
    }
    
    return $results;
}
