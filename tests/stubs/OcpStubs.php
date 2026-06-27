<?php

declare(strict_types=1);

namespace OCP;

if (!interface_exists(IConfig::class)) {
	interface IConfig
	{
		public function getAppValue(string $app, string $key, string $default = ''): string;
		public function setAppValue(string $app, string $key, string $value): void;
		public function deleteAppValue(string $app, string $key): void;
	}
}

namespace OCP\Security;

if (!interface_exists(ICrypto::class)) {
	interface ICrypto
	{
		public function encrypt(string $data): string;
		public function decrypt(string $data): string;
	}
}

namespace OCP\AppFramework;

if (!class_exists(App::class)) {
	class App
	{
		public function __construct(string $appId)
		{
		}
	}
}

namespace OCP\AppFramework\Db;

if (!class_exists(Entity::class)) {
	class Entity
	{
		protected $id;

		public function __construct()
		{
		}

		public function getId()
		{
			return $this->id;
		}

		public function setId($id): void
		{
			$this->id = $id;
		}

		protected function addType(string $field, string $type): void
		{
		}

		public function __call(string $name, array $arguments)
		{
			if (str_starts_with($name, 'get')) {
				$prop = lcfirst(substr($name, 3));
				return $this->$prop ?? null;
			}
			if (str_starts_with($name, 'set')) {
				$prop = lcfirst(substr($name, 3));
				$this->$prop = $arguments[0] ?? null;
				return $this;
			}
			throw new \BadMethodCallException($name);
		}
	}
}

namespace OCP\AppFramework\Db;

if (!class_exists(QBMapper::class)) {
	class QBMapper
	{
		public function __construct(
			protected $db,
			protected string $tableName,
			protected string $entityClass,
		) {
		}

		public function getTableName(): string
		{
			return $this->tableName;
		}
	}
}

namespace OCP\AppFramework\Db;

if (!class_exists(DoesNotExistException::class)) {
	class DoesNotExistException extends \Exception
	{
	}
}

namespace OCP;

if (!interface_exists(IDBConnection::class)) {
	interface IDBConnection
	{
		public function createSchema();
	}
}

namespace OCP\DB;

if (!interface_exists(ISchemaWrapper::class)) {
	interface ISchemaWrapper
	{
		public function hasTable(string $name): bool;
		public function getTable(string $name);
	}
}

namespace Psr\Log;

if (!interface_exists(LoggerInterface::class)) {
	interface LoggerInterface
	{
		public function warning(string $message, array $context = []): void;
	}
}
