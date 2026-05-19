# Security Hardening Guide

This guide provides comprehensive security hardening procedures for production deployment of the CQRS Template application.

## Database TLS/SSL Configuration

**REQUIRES_DBA: This configuration requires database administrator access and should be coordinated with your infrastructure team.**

### Overview

Transport Layer Security (TLS) encrypts data in transit between the application server and database server, preventing man-in-the-middle attacks and eavesdropping on sensitive data (passwords, PII, financial information).

**Compliance Requirements:**
- PCI-DSS 4.0: Requirement 4.2 - Encrypt transmission of cardholder data
- HIPAA: 164.312(e)(1) - Transmission Security
- GDPR: Article 32 - Security of Processing
- SOC 2: CC6.7 - Logical Access Security

**Threat Mitigation:**
- Prevents credential theft during transmission
- Protects against network sniffing attacks
- Ensures data integrity (prevents tampering)
- Authenticates database server identity

### Prerequisites

1. **MySQL 8.0+** with SSL support compiled in
2. **Valid SSL certificates** (production) or self-signed certificates (development)
3. **Database administrator access** to configure MySQL server
4. **Application server access** to update configuration

### Step 1: Generate SSL Certificates

#### Option A: Production (CA-Signed Certificates)

```bash
# Obtain certificates from your organization's CA or trusted provider
# Required files:
# - ca-cert.pem (Certificate Authority certificate)
# - client-cert.pem (Application client certificate)
# - client-key.pem (Application client private key)
```

#### Option B: Development/Staging (Self-Signed Certificates)

```bash
# Generate CA certificate
openssl genrsa 2048 > ca-key.pem
openssl req -new -x509 -nodes -days 3650 -key ca-key.pem -out ca-cert.pem \
  -subj "/C=US/ST=State/L=City/O=Organization/CN=MySQL-CA"

# Generate server certificate
openssl req -newkey rsa:2048 -nodes -days 3650 -keyout server-key.pem -out server-req.pem \
  -subj "/C=US/ST=State/L=City/O=Organization/CN=MySQL-Server"
openssl x509 -req -in server-req.pem -days 3650 -CA ca-cert.pem -CAkey ca-key.pem \
  -set_serial 01 -out server-cert.pem

# Generate client certificate
openssl req -newkey rsa:2048 -nodes -days 3650 -keyout client-key.pem -out client-req.pem \
  -subj "/C=US/ST=State/L=City/O=Organization/CN=MySQL-Client"
openssl x509 -req -in client-req.pem -days 3650 -CA ca-cert.pem -CAkey ca-key.pem \
  -set_serial 02 -out client-cert.pem

# Verify certificates
openssl verify -CAfile ca-cert.pem server-cert.pem client-cert.pem
```

### Step 2: Configure MySQL Server (DBA Task)

**File:** `/etc/mysql/my.cnf` or `/etc/my.cnf`

```ini
[mysqld]
# SSL Configuration
ssl-ca=/path/to/ca-cert.pem
ssl-cert=/path/to/server-cert.pem
ssl-key=/path/to/server-key.pem

# Enforce TLS for specific users (recommended)
# CREATE USER 'app_user'@'%' IDENTIFIED BY 'password' REQUIRE SSL;

# Or enforce TLS globally for all connections (strict)
require_secure_transport=ON
```

**Restart MySQL:**

```bash
sudo systemctl restart mysql

# Verify SSL is enabled
mysql -u root -p -e "SHOW VARIABLES LIKE '%ssl%';"
```

**Expected Output:**

```
+---------------+-----------------------------+
| Variable_name | Value                       |
+---------------+-----------------------------+
| have_openssl  | YES                         |
| have_ssl      | YES                         |
| ssl_ca        | /path/to/ca-cert.pem        |
| ssl_cert      | /path/to/server-cert.pem    |
| ssl_key       | /path/to/server-key.pem     |
+---------------+-----------------------------+
```

### Step 3: Configure Application (Developer Task)

**File:** `app/Config/Database.php`

```php
<?php

namespace Config;

use CodeIgniter\Database\Config;

class Database extends Config
{
    public array $default = [
        'DSN'          => '',
        'hostname'     => 'db.production.internal',
        'username'     => 'app_user',
        'password'     => getenv('DB_PASSWORD'),
        'database'     => 'cqrs_production',
        'DBDriver'     => 'MySQLi',
        'DBPrefix'     => '',
        'pConnect'     => false,
        'DBDebug'      => false,
        'charset'      => 'utf8mb4',
        'DBCollat'     => 'utf8mb4_unicode_ci',
        'swapPre'      => '',
        'encrypt'      => [
            'ssl_verify' => true,  // PRODUCTION: Verify server certificate
            'ssl_ca'     => '/path/to/ca-cert.pem',
            'ssl_cert'   => '/path/to/client-cert.pem',  // Optional: mutual TLS
            'ssl_key'    => '/path/to/client-key.pem',   // Optional: mutual TLS
        ],
        'compress'     => false,
        'strictOn'     => true,
        'failover'     => [],
        'port'         => 3306,
        'numberNative' => false,
    ];
}
```

**Environment Variables** (`.env`):

```bash
# Database Configuration
DB_HOSTNAME=db.production.internal
DB_USERNAME=app_user
DB_PASSWORD=<strong-password-from-vault>
DB_DATABASE=cqrs_production

# TLS Certificate Paths
DB_SSL_CA=/app/ssl/ca-cert.pem
DB_SSL_CERT=/app/ssl/client-cert.pem
DB_SSL_KEY=/app/ssl/client-key.pem
DB_SSL_VERIFY=true
```

**Dynamic Configuration Example:**

```php
public array $default = [
    // ... other settings
    'encrypt' => [
        'ssl_verify' => filter_var(getenv('DB_SSL_VERIFY'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true,
        'ssl_ca'     => getenv('DB_SSL_CA') ?: null,
        'ssl_cert'   => getenv('DB_SSL_CERT') ?: null,
        'ssl_key'    => getenv('DB_SSL_KEY') ?: null,
    ],
];
```

### Step 4: Testing Procedure

#### 4.1 Test Connection Without TLS (Should Fail)

```bash
# Temporarily disable TLS in Database.php
# 'encrypt' => false,

php spark migrate --all

# Expected: Connection should fail if require_secure_transport=ON
# Error: "ERROR 3159 (HY000): Connections using insecure transport are prohibited"
```

#### 4.2 Test Connection With TLS (Should Succeed)

```bash
# Re-enable TLS in Database.php
# 'encrypt' => [ ... ]

php spark migrate --all

# Expected: Migrations run successfully
```

#### 4.3 Verify TLS is Active

```bash
# Create test script: temp/verify-tls.php
<?php

require 'vendor/autoload.php';

$config = config('Database');
$db = \Config\Database::connect();

$query = $db->query("SHOW STATUS LIKE 'Ssl_cipher'");
$result = $query->getRow();

if ($result && !empty($result->Value)) {
    echo "✅ TLS is ACTIVE\n";
    echo "Cipher: {$result->Value}\n";
} else {
    echo "❌ TLS is NOT active\n";
    exit(1);
}

// Additional verification
$query = $db->query("SHOW STATUS LIKE 'Ssl_version'");
$result = $query->getRow();
echo "TLS Version: {$result->Value}\n";
```

```bash
php temp/verify-tls.php
```

**Expected Output:**

```
✅ TLS is ACTIVE
Cipher: TLS_AES_256_GCM_SHA384
TLS Version: TLSv1.3
```

### Step 5: Certificate Management

**Certificate Rotation Procedure (Annual or as needed):**

1. **Generate new certificates** (90 days before expiration)
2. **Deploy new certificates** to application servers (60 days before expiration)
3. **Update MySQL server configuration** (30 days before expiration)
4. **Restart MySQL** during maintenance window
5. **Verify all applications** connect successfully
6. **Remove old certificates** after confirmation (7 days grace period)

**Monitoring:**

```bash
# Check certificate expiration
openssl x509 -in /path/to/client-cert.pem -noout -enddate

# Create monitoring alert (30 days before expiration)
# Add to your monitoring system (Prometheus, Datadog, etc.)
```

### Rollback Plan

If TLS configuration causes connection failures:

1. **Immediate Rollback (< 5 minutes):**
   ```bash
   # On application servers:
   # Set in .env:
   DB_SSL_VERIFY=false

   # Or temporarily disable in Database.php:
   'encrypt' => false,

   # Restart application
   sudo systemctl restart php-fpm  # or your application service
   ```

2. **Investigate and Fix:**
   - Check certificate paths are correct
   - Verify file permissions (readable by application user)
   - Check MySQL error log: `tail -f /var/log/mysql/error.log`
   - Verify network connectivity to database server
   - Confirm MySQL SSL configuration: `SHOW VARIABLES LIKE '%ssl%';`

3. **Re-enable TLS After Fix:**
   - Test in staging environment first
   - Deploy fix to production during maintenance window
   - Monitor connection success rate

### Security Considerations

**Development/Staging:**
- Self-signed certificates acceptable
- `ssl_verify => false` allowed (but not recommended)
- Document security exceptions

**Production:**
- **MUST use CA-signed certificates**
- **MUST set `ssl_verify => true`** (verify server identity)
- **MUST rotate certificates annually** (or per policy)
- **MUST restrict certificate access** (0400 permissions)
- **SHOULD use mutual TLS** (client certificates) for highest security

**File Permissions:**

```bash
# Certificate files should only be readable by application user
chmod 600 /app/ssl/*.pem
chown www-data:www-data /app/ssl/*.pem  # or your application user
```

### Compliance Checklist

- [ ] TLS 1.2 or higher enabled (`ssl_version => TLSv1.2`)
- [ ] Strong cipher suites only (no SSLv3, TLS 1.0, TLS 1.1)
- [ ] Valid CA-signed certificates (production)
- [ ] Certificate expiration monitoring in place
- [ ] Annual certificate rotation procedure documented
- [ ] Connection verification tests automated
- [ ] Rollback procedure tested
- [ ] Security team approval obtained
- [ ] Change control ticket submitted

### Additional Resources

- **MySQL SSL/TLS Documentation:** https://dev.mysql.com/doc/refman/8.0/en/using-encrypted-connections.html
- **CodeIgniter Database Configuration:** https://codeigniter.com/user_guide/database/configuration.html
- **PCI-DSS Requirements:** https://www.pcisecuritystandards.org/
- **OWASP Transport Layer Protection:** https://cheatsheetseries.owasp.org/cheatsheets/Transport_Layer_Protection_Cheat_Sheet.html

### Support

For assistance with TLS configuration:
- Contact your Database Administrator for server-side configuration
- Contact your Security Team for certificate generation and approval
- Contact your DevOps Team for application deployment

---

**STATUS:** Ready for DBA implementation
**PRIORITY:** HIGH (required for production deployment)
**RISK LEVEL:** LOW (rollback procedure available)
