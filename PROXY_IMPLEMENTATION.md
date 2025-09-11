# IMAP Proxy Support Implementation

## Overview

This implementation adds full HTTP and SOCKS5 proxy support to the email viewing system, replacing the limitation of PHP's native IMAP extension which doesn't support proxy connections.

## Key Changes

### 1. New ProxyImapClient Class (`backend/utils/proxy_imap_client.php`)

A custom IMAP client implementation that supports:
- **HTTP Proxy**: Uses CONNECT method to tunnel IMAP connections
- **SOCKS5 Proxy**: Implements SOCKS5 protocol for proxy connections
- **SSL/TLS Support**: Works with encrypted connections through proxies
- **Authentication**: Supports proxy authentication (username/password)
- **Error Handling**: Provides detailed error messages for troubleshooting

### 2. Enhanced MailFetcher Class (`backend/utils/mail_fetcher.php`)

Updated to support both proxy and direct connections:
- **Automatic Proxy Detection**: Checks for available proxies and uses them when available
- **Fallback Mechanism**: Falls back to direct connection if proxy fails
- **Mixed Protocol Support**: IMAP through proxy, POP3 via direct connection
- **Unified Interface**: Maintains compatibility with existing API calls

### 3. Database Integration

The existing `proxy_pool` table supports:
- **Multiple Proxy Types**: HTTP and SOCKS5
- **Statistics Tracking**: Success/failure counts and response times
- **Status Management**: Active/inactive and verified/unverified states
- **Authentication**: Username/password for proxy servers

## Usage

### Admin Interface

1. **Add Proxies**: Use the admin panel to add HTTP or SOCKS5 proxies
2. **Test Proxies**: Verify proxy connectivity before use
3. **Monitor Performance**: Track success rates and response times

### API Behavior

- **Mail Retrieval**: `/backend/api/get_mail.php` automatically uses proxies when available
- **Connection Testing**: `/backend/api/test_connection.php` tests through proxy when configured
- **Transparent Operation**: Front-end code requires no changes

### Proxy Priority

1. **IMAP + Proxy Available**: Uses ProxyImapClient through configured proxy
2. **IMAP + No Proxy**: Uses native PHP IMAP extension directly
3. **POP3**: Always uses native PHP IMAP extension (proxy support can be added later)

## Technical Details

### HTTP Proxy Connection Flow

1. Connect to proxy server
2. Send HTTP CONNECT request to target IMAP server
3. Establish tunnel through proxy
4. Upgrade to SSL/TLS if required
5. Perform IMAP authentication and operations

### SOCKS5 Proxy Connection Flow

1. Connect to SOCKS5 proxy server
2. Perform SOCKS5 handshake and authentication
3. Request connection to target IMAP server
4. Establish tunneled connection
5. Upgrade to SSL/TLS if required
6. Perform IMAP authentication and operations

### Error Handling

- **Proxy Connection Failures**: Automatically falls back to direct connection
- **Authentication Errors**: Provides specific error messages
- **Network Issues**: Graceful handling with detailed diagnostics
- **SSL Certificate Issues**: Configurable SSL verification

## Configuration

### Database Schema

The system uses the existing `proxy_pool` table with these key fields:

```sql
- proxy_type: 'http' or 'socks5'
- proxy_host: Proxy server hostname/IP
- proxy_port: Proxy server port
- proxy_username: Optional authentication username
- proxy_password: Optional authentication password
- is_active: Enable/disable proxy
- is_verified: Automatically updated based on test results
```

### Automatic Proxy Selection

The system automatically selects the best available proxy based on:
1. Active status (`is_active = 1`)
2. Verification status (prioritizes verified proxies)
3. Response time (faster proxies preferred)
4. Success rate (higher success rate preferred)

## API Response Changes

### Enhanced JSON Responses

All API responses now include proxy information:

```json
{
    "success": true,
    "mail": {...},
    "proxy": {
        "used": true,
        "type": "http",
        "host": "proxy.example.com",
        "port": 8080,
        "name": "Primary HTTP Proxy"
    }
}
```

### Error Standardization

- All errors return valid JSON format
- Detailed diagnostic information for troubleshooting
- Specific error types for different failure scenarios

## Dependencies

### Required PHP Extensions

- **PDO SQLite**: For database operations (already required)
- **cURL**: For proxy testing (already available)
- **OpenSSL**: For SSL/TLS connections (standard)
- **Sockets**: For low-level socket operations (standard)

### Optional Dependencies

A `composer.json` file has been created for future dependency management:

```json
{
    "require": {
        "php": ">=7.4"
    }
}
```

## Deployment Notes

### 1. Backup Database

Before deployment, backup the existing `mail.sqlite` database.

### 2. Database Migration

The system automatically creates the `proxy_pool` table if it doesn't exist. No manual migration required.

### 3. Test Proxy Configuration

1. Access admin panel
2. Add at least one test proxy server
3. Use "Test Connection" to verify functionality
4. Monitor proxy statistics for performance

### 4. Compatibility

- **Existing Mail Accounts**: No changes required
- **API Clients**: Fully backward compatible
- **Direct Connections**: Continue to work as before
- **Error Handling**: Improved with better JSON responses

## Troubleshooting

### Common Issues

1. **"No available proxy"**: Add proxies via admin panel and ensure they're active
2. **"Proxy connection failed"**: Verify proxy server settings and network connectivity
3. **"Authentication failed"**: Check proxy username/password configuration
4. **"SSL handshake failed"**: Verify SSL certificate settings or disable SSL verification

### Debug Information

The system provides detailed diagnostic information for troubleshooting:
- Connection method used (proxy vs direct)
- Proxy server details
- Response times and error messages
- Automatic fallback notifications

## Performance Impact

### Benefits

- **Improved Connectivity**: Bypass network restrictions
- **Load Distribution**: Distribute connections across multiple proxies
- **Reliability**: Automatic fallback ensures service continuity

### Considerations

- **Latency**: Proxy connections may add ~100-500ms latency
- **Bandwidth**: Minimal impact on bandwidth usage
- **Resource Usage**: Slightly higher memory usage for socket operations

## Security Considerations

### Proxy Authentication

- Credentials stored in database (consider encryption for production)
- Support for username/password authentication
- No support for advanced authentication methods (can be added)

### SSL/TLS

- Full SSL/TLS support through proxies
- Certificate verification configurable
- Encrypted proxy tunnels for HTTP CONNECT

### Network Security

- All IMAP traffic encrypted when SSL enabled
- Proxy traffic uses standard protocols
- No additional attack vectors introduced

## Future Enhancements

1. **POP3 Proxy Support**: Extend proxy support to POP3 protocol
2. **Proxy Pool Rotation**: Automatic rotation for load balancing
3. **Proxy Health Monitoring**: Automated health checks and alerting
4. **Encryption**: Encrypt proxy credentials in database
5. **Advanced Authentication**: Support for NTLM, Kerberos, etc.
6. **IPv6 Support**: Add IPv6 proxy server support

## Testing

The implementation has been tested with:
- ✅ Database initialization and schema creation
- ✅ Proxy manager functionality and selection logic
- ✅ IMAP client creation and connection handling
- ✅ API integration and JSON response formatting
- ✅ Error handling and fallback mechanisms
- ✅ Statistics tracking and performance monitoring

## Support

For issues or questions regarding the proxy implementation:
1. Check the admin panel proxy status and logs
2. Verify proxy server configuration and connectivity
3. Review API response messages for detailed error information
4. Monitor proxy statistics for performance trends