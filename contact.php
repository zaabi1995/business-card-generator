<?php
/**
 * Cardify - Contact Us
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Contact Cardify — Get in Touch';
$pageDescription = 'Questions about business cards? Contact Cardify for support, partnerships, or print shop inquiries in Oman. We\'re here to help.';
$canonicalUrl = 'https://cardify.om/contact';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

// Enable dynamic navigation
$showNavigation = true;

// Handle form submission
$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    
    if (empty($name) || empty($email) || empty($message)) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Send styled HTML email via Mailer
        require_once INCLUDES_DIR . '/Mailer.php';

        $date = date('M j, Y \\a\\t g:i A');
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $safeSubject = htmlspecialchars($subject ?: 'General Inquiry', ENT_QUOTES, 'UTF-8');
        $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

        $htmlBody = <<<HTMLEMAIL
<!DOCTYPE html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f7;padding:40px 0">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%">
<tr><td style="background:#824598;padding:32px 40px;border-radius:8px 8px 0 0">
  <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:600">New Contact Form Submission</h1>
  <p style="margin:6px 0 0;color:rgba(255,255,255,0.8);font-size:13px">{$date}</p>
</td></tr>
<tr><td style="background:#ffffff;padding:36px 40px">
  <table cellpadding="0" cellspacing="0" style="margin-bottom:28px"><tr>
    <td style="background:#f3e8fa;color:#824598;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;letter-spacing:0.5px">{$safeSubject}</td>
  </tr></table>
  <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px">
    <tr><td style="padding:12px 0;border-bottom:1px solid #eef0f3">
        <span style="color:#8c8fa3;font-size:12px;text-transform:uppercase;letter-spacing:0.8px;display:block;margin-bottom:4px">Name</span>
        <span style="color:#1a1a2e;font-size:16px;font-weight:600">{$safeName}</span>
    </td></tr>
    <tr><td style="padding:12px 0;border-bottom:1px solid #eef0f3">
        <span style="color:#8c8fa3;font-size:12px;text-transform:uppercase;letter-spacing:0.8px;display:block;margin-bottom:4px">Email</span>
        <a href="mailto:{$safeEmail}" style="color:#824598;font-size:16px;text-decoration:none;font-weight:500">{$safeEmail}</a>
    </td></tr>
  </table>
  <div style="margin-bottom:28px">
    <span style="color:#8c8fa3;font-size:12px;text-transform:uppercase;letter-spacing:0.8px;display:block;margin-bottom:8px">Message</span>
    <div style="background:#f8f9fb;border-left:3px solid #824598;padding:16px 20px;border-radius:0 6px 6px 0;color:#2d2d3f;font-size:15px;line-height:1.6">
      {$safeMessage}
    </div>
  </div>
  <table cellpadding="0" cellspacing="0" style="margin:0 auto"><tr>
    <td style="background:#824598;border-radius:6px;padding:14px 32px">
      <a href="mailto:{$safeEmail}?subject=Re: {$safeSubject}" style="color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;display:block">Reply to {$safeName}</a>
    </td>
  </tr></table>
</td></tr>
<tr><td style="padding:24px 40px;text-align:center;border-top:1px solid #eef0f3;background:#fafbfc;border-radius:0 0 8px 8px">
  <p style="margin:0;color:#8c8fa3;font-size:12px">This message was sent from the contact form on <a href="https://cardify.om" style="color:#824598;text-decoration:none">cardify.om</a></p>
</td></tr>
</table>
</td></tr>
</table>
</body></html>
HTMLEMAIL;

        $sent = Mailer::send(
            'info@cardify.om',
            "Cardify Contact: {$safeSubject} — {$safeName}",
            $htmlBody,
            [],
            ['replyTo' => $email]
        );
        $success = true;
    }
    } // end CSRF else
}

require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen bg-gray-50">
    <!-- Header -->
    <div class="bg-white pt-28 pb-12">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h1 class="text-4xl font-bold text-gray-900 mb-3">Contact Us</h1>
            <p class="text-gray-500 text-lg max-w-2xl mx-auto">
                Have questions? We'd love to hear from you. Send us a message and we'll respond as soon as possible.
            </p>
        </div>
    </div>

    <!-- Content -->
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <div class="grid lg:grid-cols-3 gap-12">
            
            <!-- Contact Info -->
            <div class="lg:col-span-1 space-y-8">
                
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <div class="w-12 h-12 rounded-lg bg-blue-100 flex items-center justify-center mb-4">
                        <i class="fa-solid fa-building text-xl text-blue-600"></i>
                    </div>
                    <h3 class="font-bold text-gray-900 mb-2">Company</h3>
                    <p class="text-gray-600">
                        <strong>Cardify</strong><br>
                        Professional Business Card Platform
                    </p>
                </div>

                <div class="bg-white rounded-xl shadow-sm p-6">
                    <div class="w-12 h-12 rounded-lg bg-green-100 flex items-center justify-center mb-4">
                        <i class="fa-solid fa-location-dot text-xl text-green-600"></i>
                    </div>
                    <h3 class="font-bold text-gray-900 mb-2">Address</h3>
                    <p class="text-gray-600">
                        AK Tower, PO Box 2237<br>
                        Bousher, Muscat 133<br>
                        Sultanate of Oman
                    </p>
                </div>

                <div class="bg-white rounded-xl shadow-sm p-6">
                    <div class="w-12 h-12 rounded-lg bg-purple-100 flex items-center justify-center mb-4">
                        <i class="fa-solid fa-envelope text-xl text-purple-600"></i>
                    </div>
                    <h3 class="font-bold text-gray-900 mb-2">Get in Touch</h3>
                    <p class="text-gray-600">
                        Use the contact form to reach our team for general inquiries, support, or sales questions.
                    </p>
                </div>

                <div class="bg-white rounded-xl shadow-sm p-6">
                    <div class="w-12 h-12 rounded-lg bg-orange-100 flex items-center justify-center mb-4">
                        <i class="fa-solid fa-share-nodes text-xl text-orange-600"></i>
                    </div>
                    <h3 class="font-bold text-gray-900 mb-2">Social Media</h3>
                    <div class="flex gap-3 mt-4">
                        <a href="https://instagram.com/cardifyom" target="_blank" rel="noopener noreferrer" 
                           class="w-10 h-10 rounded-lg bg-gray-100 hover:bg-pink-600 hover:text-white flex items-center justify-center transition-colors">
                            <i class="fa-brands fa-instagram text-gray-600"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Contact Form -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-2xl shadow-sm p-8">
                    <?php if ($success): ?>
                        <div class="text-center py-12">
                            <div class="w-20 h-20 rounded-full bg-green-100 flex items-center justify-center mx-auto mb-6">
                                <i class="fa-solid fa-check text-3xl text-green-600"></i>
                            </div>
                            <h2 class="text-2xl font-bold text-gray-900 mb-4">Message Sent!</h2>
                            <p class="text-gray-600 mb-8">
                                Thank you for reaching out. We'll get back to you within 24-48 hours.
                            </p>
                            <a href="<?php echo getBasePath(); ?>" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 font-medium">
                                <i class="fa-solid fa-arrow-left"></i>
                                Back to Home
                            </a>
                        </div>
                    <?php else: ?>
                        <h2 class="text-2xl font-bold text-gray-900 mb-6">Send us a Message</h2>
                        
                        <?php if ($error): ?>
                            <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700">
                                <i class="fa-solid fa-circle-exclamation mr-2"></i>
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" class="space-y-6">
                            <?php echo csrfField(); ?>
                            <div class="grid md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Your Name *</label>
                                    <input type="text" name="name" required
                                           value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                                           class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="John Doe">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Email Address *</label>
                                    <input type="email" name="email" required
                                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                           class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                           placeholder="john@example.com">
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Subject</label>
                                <select name="subject" 
                                        class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    <option value="general">General Inquiry</option>
                                    <option value="support">Technical Support</option>
                                    <option value="sales">Sales Question</option>
                                    <option value="partnership">Partnership Opportunity</option>
                                    <option value="feedback">Feedback</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Message *</label>
                                <textarea name="message" rows="6" required
                                          class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                          placeholder="How can we help you?"><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
                            </div>

                            <button type="submit" 
                                    class="w-full md:w-auto px-8 py-4 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors flex items-center justify-center gap-2">
                                <i class="fa-solid fa-paper-plane"></i>
                                Send Message
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Back to Home -->
        <div class="mt-12 text-center">
            <a href="<?php echo getBasePath(); ?>" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 font-medium">
                <i class="fa-solid fa-arrow-left"></i>
                Back to Home
            </a>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
