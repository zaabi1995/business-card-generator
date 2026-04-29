-- Seed: Oman Housing Bank S.A.O.G. tenant
-- slug: ohb  →  ohb.cardify.om
-- Login: OTP-only (password_hash fields populated with random unused bcrypt)
-- Admins: Adnan R. (email) and Al-Hadi (phone)
--
-- Safe to re-run: guarded by INSERT IGNORE on the unique slug/email columns.

INSERT IGNORE INTO companies
  (id, slug, name, admin_email, password_hash, plan, status, country, currency, created_at, updated_at, onboarding_completed)
VALUES
  ('a0b10000-0000-0000-0000-00000000b001',
   'ohb',
   'Oman Housing Bank S.A.O.G.',
   'Adnan.r@ohb.co.om',
   '$2y$10$OtpOnlyTenantNoPasswordLoginAllowedXXXXXXXXXXXXXXXXXX',
   'enterprise',
   'active',
   'OM',
   'OMR',
   NOW(),
   NOW(),
   0);

-- Adnan R., finance contact (email login)
INSERT IGNORE INTO users
  (id, email, password_hash, role, company_id, name, status, phone, created_at, updated_at)
VALUES
  ('a0b10000-0000-0000-0000-00000000u001',
   'Adnan.r@ohb.co.om',
   '$2y$10$OtpOnlyTenantNoPasswordLoginAllowedXXXXXXXXXXXXXXXXXX',
   'admin',
   'a0b10000-0000-0000-0000-00000000b001',
   'Adnan R.',
   'active',
   NULL,
   NOW(),
   NOW());

-- Al-Hadi, primary WhatsApp contact (phone login)
INSERT IGNORE INTO users
  (id, email, password_hash, role, company_id, name, status, phone, created_at, updated_at)
VALUES
  ('a0b10000-0000-0000-0000-00000000u002',
   'hadi@ohb.co.om',
   '$2y$10$OtpOnlyTenantNoPasswordLoginAllowedXXXXXXXXXXXXXXXXXX',
   'admin',
   'a0b10000-0000-0000-0000-00000000b001',
   'Al-Hadi',
   'active',
   '96896995339',
   NOW(),
   NOW());
