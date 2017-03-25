#!/usr/bin/env php
<?php
	require_once(dirname(__FILE__) . '/init_functions.php');

	// This will delete the database and seed it with test data.
	$pdo = DB::get()->getPDO();
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 0;');
	$pdo->exec('DROP TABLE users;');
	$pdo->exec('DROP TABLE domains;');
	$pdo->exec('DROP TABLE domain_access;');
	$pdo->exec('DROP TABLE records;');
	$pdo->exec('DROP TABLE apikeys;');
	$pdo->exec('DROP TABLE hooks;');
	$pdo->exec('DROP TABLE permissions;');
	$pdo->exec('DROP TABLE __MetaData;');
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 1;');
	initDataServer(DB::get());

	$domains = array();

	$admin = new User(DB::get());
	$admin->setEmail('admin@example.org')->setRealName('Admin User')->setPassword('password')->setPermission('all', true)->save();

	$adminKey = new APIKey(DB::get());
	$adminKey->setKey('69299C29-5BED-447D-B2F9-840DD01FE0B5')->setDescription('Test Key')->setUserID($admin->getID());
	$adminKey->setDomainRead(true)->setDomainWrite(true);
	$adminKey->setUserRead(true)->setUserWrite(true);
	$adminKey->save();

	for ($i = 1; $i <= 5; $i++) {
		$domain = new Domain(DB::get());
		$domain->setDomain('test' . $i . '.com')->setAccess($admin->getID(), 'Owner')->save();
		$domains[] = $domain;
		HookManager::get()->handle('delete_domain', [$domain]);
		HookManager::get()->handle('add_domain', [$domain]);
	}

	for ($i = 1; $i <= 5; $i++) {
		$user = new User(DB::get());
		$user->setEmail('user' . $i . '@example.org')->setRealName('Normal User ' . $i)->setPassword('password')->setPermission('all', false)->save();

		$domain = new Domain(DB::get());
		$domain->setDomain('example' . $i . '.org')->setAccess($user->getID(), 'Owner')->save();
		if ($i % 2 == 0) {
			$domain->setAccess($admin->getID(), 'Write')->save();
		}
		$domains[] = $domain;
		HookManager::get()->handle('delete_domain', [$domain]);
		HookManager::get()->handle('add_domain', [$domain]);
	}


	for ($i = 1; $i <= 5; $i++) {
		$domain = new Domain(DB::get());
		$domain->setDomain('unowned' . $i . '.com')->save();
		if ($i % 2 == 0) {
			$domain->setAccess($admin->getID(), 'Read')->save();
		}
		$domains[] = $domain;
		HookManager::get()->handle('delete_domain', [$domain]);
		HookManager::get()->handle('add_domain', [$domain]);
	}

	foreach ($domains as $domain) {

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName($domain->getDomain());
		$record->setType('SOA');
		$record->setContent('ns1.mydnshost.co.uk. dnsadmin.dataforce.org.uk. 2017030500 86400 7200 2419200 60');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->validate();
		$record->save();
		HookManager::get()->handle('add_record', [$domain, $record]);

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName($domain->getDomain());
		$record->setType('NS');
		$record->setContent('dev.mydnshost.co.uk');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->validate();
		$record->save();
		HookManager::get()->handle('add_record', [$domain, $record]);

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName($domain->getDomain());
		$record->setType('A');
		$record->setContent('127.0.0.1');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->validate();
		$record->save();
		HookManager::get()->handle('add_record', [$domain, $record]);

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName('www.' . $domain->getDomain());
		$record->setType('A');
		$record->setContent('127.0.0.1');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->validate();
		$record->save();
		HookManager::get()->handle('add_record', [$domain, $record]);

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName($domain->getDomain());
		$record->setType('AAAA');
		$record->setContent('::1');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->validate();
		$record->save();
		HookManager::get()->handle('add_record', [$domain, $record]);

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName('www.' . $domain->getDomain());
		$record->setType('AAAA');
		$record->setContent('::1');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->validate();
		$record->save();
		HookManager::get()->handle('add_record', [$domain, $record]);

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName('test.' . $domain->getDomain());
		$record->setType('CNAME');
		$record->setContent('www.' . $domain->getDomain());
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->validate();
		$record->save();
		HookManager::get()->handle('add_record', [$domain, $record]);

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName('txt.' . $domain->getDomain());
		$record->setType('TXT');
		$record->setContent('Some Text Record');
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->validate();
		$record->save();
		HookManager::get()->handle('add_record', [$domain, $record]);

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName($domain->getDomain());
		$record->setType('MX');
		$record->setPriority('10');
		$record->setContent($domain->getDomain());
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->validate();
		$record->save();
		HookManager::get()->handle('add_record', [$domain, $record]);

		$record = new Record(DB::get());
		$record->setDomainID($domain->getID());
		$record->setName($domain->getDomain());
		$record->setType('MX');
		$record->setPriority('50');
		$record->setContent('www.' .  $domain->getDomain());
		$record->setTTL(86400);
		$record->setChangedAt(time());
		$record->validate();
		$record->save();
		HookManager::get()->handle('add_record', [$domain, $record]);

		HookManager::get()->handle('records_changed', [$domain]);
	}

	exit(0);
