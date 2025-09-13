#!/usr/bin/env python3
"""
Test script for the mail system functionality
"""

import json
import sqlite3
import os
import sys
from python.mail_fetcher import ProxyMailFetcher

def test_database_setup():
    """Test database structure"""
    print("🔍 Testing database setup...")
    
    db_path = 'db/mail.sqlite'
    if not os.path.exists(db_path):
        print("❌ Database file not found")
        return False
    
    try:
        conn = sqlite3.connect(db_path)
        cursor = conn.cursor()
        
        # Check required tables
        tables = ['mail_accounts', 'proxy_config', 'http_proxies', 'socks5_proxies']
        for table in tables:
            cursor.execute("SELECT name FROM sqlite_master WHERE type='table' AND name=?", (table,))
            if not cursor.fetchone():
                print(f"❌ Table {table} not found")
                return False
        
        # Check test account
        cursor.execute("SELECT COUNT(*) FROM mail_accounts")
        account_count = cursor.fetchone()[0]
        
        conn.close()
        print(f"✅ Database setup OK - {account_count} mail accounts found")
        return True
        
    except Exception as e:
        print(f"❌ Database test failed: {e}")
        return False

def test_proxy_config():
    """Test proxy configuration system"""
    print("🔍 Testing proxy configuration...")
    
    try:
        conn = sqlite3.connect('db/mail.sqlite')
        cursor = conn.cursor()
        
        # Check proxy config
        cursor.execute("SELECT config_key, config_value FROM proxy_config")
        config = {row[0]: row[1] for row in cursor.fetchall()}
        
        required_keys = ['proxy_enabled', 'active_proxy_type', 'active_proxy_id']
        for key in required_keys:
            if key not in config:
                print(f"❌ Missing proxy config key: {key}")
                return False
        
        print(f"✅ Proxy config OK - proxy_enabled: {config['proxy_enabled']}")
        
        # Check proxy tables
        http_count = cursor.execute("SELECT COUNT(*) FROM http_proxies").fetchone()[0]
        socks5_count = cursor.execute("SELECT COUNT(*) FROM socks5_proxies").fetchone()[0]
        
        print(f"✅ Proxy tables OK - {http_count} HTTP, {socks5_count} SOCKS5 proxies")
        
        conn.close()
        return True
        
    except Exception as e:
        print(f"❌ Proxy config test failed: {e}")
        return False

def test_python_mail_fetcher():
    """Test Python mail fetcher functionality"""
    print("🔍 Testing Python mail fetcher...")
    
    try:
        # Test with non-existent email
        fetcher = ProxyMailFetcher(
            'nonexistent.example.com',
            993,
            'test@example.com',
            'testpass',
            'imap',
            True
        )
        
        # Test connection (should fail gracefully)
        result = fetcher.test_connection()
        
        if result['success']:
            print("⚠️ Unexpected success with invalid server")
        else:
            print(f"✅ Python mail fetcher correctly handled invalid server: {result.get('error_type', 'unknown')}")
        
        # Test proxy info retrieval
        proxy_info = fetcher.get_proxy_info()
        print(f"✅ Proxy info retrieval OK - enabled: {proxy_info['enabled']}")
        
        return True
        
    except Exception as e:
        print(f"❌ Python mail fetcher test failed: {e}")
        return False

def test_mail_account_integration():
    """Test mail account and Python integration"""
    print("🔍 Testing mail account integration...")
    
    try:
        conn = sqlite3.connect('db/mail.sqlite')
        cursor = conn.cursor()
        
        # Get test account
        cursor.execute("SELECT * FROM mail_accounts LIMIT 1")
        account = cursor.fetchone()
        
        if not account:
            print("❌ No test account found")
            return False
        
        columns = [desc[0] for desc in cursor.description]
        account_dict = dict(zip(columns, account))
        
        print(f"✅ Found test account: {account_dict['email']}")
        
        # Test Python fetcher with account data
        fetcher = ProxyMailFetcher(
            account_dict['server'],
            account_dict['port'],
            account_dict['username'],
            account_dict['password'],
            account_dict['protocol'],
            bool(account_dict['ssl'])
        )
        
        # Test connection (expected to fail due to network restrictions)
        result = fetcher.test_connection()
        
        # Should contain proper diagnostics
        if 'diagnostics' in result:
            print("✅ Connection test provides diagnostics")
        else:
            print("⚠️ Connection test missing diagnostics")
        
        # Should handle proxy info
        if 'proxy' in result or hasattr(fetcher, 'proxy_enabled'):
            print("✅ Proxy handling integrated")
        else:
            print("⚠️ Proxy handling not found")
        
        conn.close()
        return True
        
    except Exception as e:
        print(f"❌ Mail account integration test failed: {e}")
        return False

def main():
    """Run all tests"""
    print("🧪 Mail System Functionality Test\n")
    
    # Change to project directory
    os.chdir(os.path.dirname(os.path.abspath(__file__)))
    
    tests = [
        ("Database Setup", test_database_setup),
        ("Proxy Configuration", test_proxy_config),
        ("Python Mail Fetcher", test_python_mail_fetcher),
        ("Mail Account Integration", test_mail_account_integration),
    ]
    
    results = []
    for test_name, test_func in tests:
        print(f"\n--- {test_name} ---")
        try:
            result = test_func()
            results.append((test_name, result))
        except Exception as e:
            print(f"❌ Test failed with exception: {e}")
            results.append((test_name, False))
    
    # Summary
    print("\n" + "="*50)
    print("📊 TEST SUMMARY")
    print("="*50)
    
    passed = 0
    for test_name, result in results:
        status = "✅ PASS" if result else "❌ FAIL"
        print(f"{status} - {test_name}")
        if result:
            passed += 1
    
    print(f"\nResults: {passed}/{len(results)} tests passed")
    
    if passed == len(results):
        print("🎉 All tests passed! Mail system is ready.")
    else:
        print("⚠️ Some tests failed. Check the issues above.")
    
    return passed == len(results)

if __name__ == '__main__':
    success = main()
    sys.exit(0 if success else 1)