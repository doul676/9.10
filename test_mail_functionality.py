#!/usr/bin/env python3
"""
Test script for mail fetcher functionality
Tests both direct connection and proxy scenarios
"""

import sys
import os
import sqlite3
import json

# Add the parent directory to Python path to import mail_fetcher
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'python'))

from mail_fetcher import ProxyMailFetcher

def test_proxy_configuration():
    """Test proxy configuration reading"""
    print("=== Testing Proxy Configuration ===")
    
    # Test with mock email account
    fetcher = ProxyMailFetcher("imap.example.com", 993, "test", "test", "imap", True)
    
    proxy_info = fetcher.get_proxy_info()
    print(f"Proxy enabled: {proxy_info['enabled']}")
    if proxy_info['enabled']:
        print(f"Proxy info: {proxy_info['info']}")
    
    return proxy_info['enabled']

def test_connection_diagnostics():
    """Test connection diagnostics with a non-existent server"""
    print("\n=== Testing Connection Diagnostics ===")
    
    # Test with a server that will definitely fail to connect
    fetcher = ProxyMailFetcher("nonexistent.example.com", 993, "test", "test", "imap", True)
    
    result = fetcher.test_connection()
    print(f"Connection test result: {result['success']}")
    print(f"Message: {result['message']}")
    print("Diagnostics:")
    for key, value in result.get('diagnostics', {}).items():
        print(f"  {key}: {value}")
    
    return result

def test_database_connection():
    """Test database connectivity and structure"""
    print("\n=== Testing Database Connection ===")
    
    try:
        db_path = os.path.join(os.path.dirname(__file__), 'db', 'mail.sqlite')
        conn = sqlite3.connect(db_path)
        cursor = conn.cursor()
        
        # Test proxy configuration
        cursor.execute("SELECT config_key, config_value FROM proxy_config")
        proxy_config = {row[0]: row[1] for row in cursor.fetchall()}
        
        print("Proxy configuration:")
        for key, value in proxy_config.items():
            print(f"  {key}: {value}")
        
        # Test mail accounts
        cursor.execute("SELECT COUNT(*) FROM mail_accounts")
        account_count = cursor.fetchone()[0]
        print(f"Mail accounts in database: {account_count}")
        
        # Test proxy tables
        cursor.execute("SELECT COUNT(*) FROM http_proxies WHERE status = 1")
        http_proxy_count = cursor.fetchone()[0]
        print(f"Active HTTP proxies: {http_proxy_count}")
        
        cursor.execute("SELECT COUNT(*) FROM socks5_proxies WHERE status = 1")
        socks5_proxy_count = cursor.fetchone()[0]
        print(f"Active SOCKS5 proxies: {socks5_proxy_count}")
        
        conn.close()
        return True
        
    except Exception as e:
        print(f"Database test failed: {e}")
        return False

def main():
    """Run all tests"""
    print("Starting Mail Fetcher Tests...\n")
    
    # Test database
    db_ok = test_database_connection()
    
    if not db_ok:
        print("Database tests failed. Cannot continue.")
        return False
    
    # Test proxy configuration
    proxy_enabled = test_proxy_configuration()
    
    # Test connection diagnostics
    diag_result = test_connection_diagnostics()
    
    print("\n=== Test Summary ===")
    print(f"Database connectivity: {'✅' if db_ok else '❌'}")
    print(f"Proxy configuration: {'✅' if proxy_enabled else '⚠️  Disabled'}")
    print(f"Connection diagnostics: {'✅' if 'diagnostics' in diag_result else '❌'}")
    print(f"Error handling: {'✅' if not diag_result['success'] else '⚠️  Unexpected success'}")
    
    return True

if __name__ == "__main__":
    main()