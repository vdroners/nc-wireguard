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

namespace OCP\AppFramework\Bootstrap;

if (!interface_exists(IRegistrationContext::class)) {
	interface IRegistrationContext
	{
		public function registerEventListener(string $event, string $listener, int $priority = 0): void;
	}
}

if (!interface_exists(IBootContext::class)) {
	interface IBootContext
	{
		public function getAppContainer();
	}
}

if (!interface_exists(IBootstrap::class)) {
	interface IBootstrap
	{
		public function register(IRegistrationContext $context): void;
		public function boot(IBootContext $context): void;
	}
}

namespace OCP\AppFramework;

if (!class_exists(Http::class)) {
	class Http
	{
		public const STATUS_OK = 200;
		public const STATUS_NO_CONTENT = 204;
		public const STATUS_BAD_REQUEST = 400;
		public const STATUS_UNAUTHORIZED = 401;
		public const STATUS_FORBIDDEN = 403;
		public const STATUS_NOT_FOUND = 404;
		public const STATUS_PRECONDITION_FAILED = 412;
		public const STATUS_UNPROCESSABLE_ENTITY = 422;
		public const STATUS_INTERNAL_SERVER_ERROR = 500;
		public const STATUS_GONE = 410;
		public const STATUS_TOO_MANY_REQUESTS = 429;
		public const STATUS_BAD_GATEWAY = 502;
		public const STATUS_SERVICE_UNAVAILABLE = 503;
	}
}

if (!class_exists(Controller::class)) {
	class Controller
	{
		public function __construct(protected string $appName, protected $request)
		{
		}
	}
}

namespace OCP\AppFramework\Http;

if (!class_exists(Response::class)) {
	class Response
	{
		private int $status = 200;
		/** @var array<string, string> */
		private array $headers = [];

		public function setStatus(int $status): static
		{
			$this->status = $status;
			return $this;
		}

		public function getStatus(): int
		{
			return $this->status;
		}

		public function addHeader($name, $value): static
		{
			$this->headers[(string) $name] = (string) $value;
			return $this;
		}

		/** @return array<string, string> */
		public function getHeaders(): array
		{
			return $this->headers;
		}
	}
}

if (!class_exists(JSONResponse::class)) {
	class JSONResponse extends Response
	{
		public function __construct(private mixed $data = [], int $status = 200)
		{
			$this->setStatus($status);
		}

		public function getData(): mixed
		{
			return $this->data;
		}
	}
}

if (!class_exists(DataDownloadResponse::class)) {
	class DataDownloadResponse extends Response
	{
		public function __construct(
			private string $content = '',
			private string $filename = '',
			private string $contentType = '',
		) {
		}

		public function getContent(): string
		{
			return $this->content;
		}

		public function getFilename(): string
		{
			return $this->filename;
		}

		public function getContentType(): string
		{
			return $this->contentType;
		}
	}
}

namespace OCP;

if (!interface_exists(IRequest::class)) {
	interface IRequest
	{
		public function getParam(string $key, $default = null);
		public function getHeader(string $name): string;
		public function getRemoteAddress(): string;
	}
}

namespace OCP\AppFramework\Http\Attribute;

if (!class_exists(AdminRequired::class)) {
	#[\Attribute]
	class AdminRequired
	{
	}
}

if (!class_exists(NoCSRFRequired::class)) {
	#[\Attribute]
	class NoCSRFRequired
	{
	}
}

if (!class_exists(PublicPage::class)) {
	#[\Attribute]
	class PublicPage
	{
	}
}

namespace OCP;

if (!interface_exists(IUser::class)) {
	interface IUser
	{
		public function getUID(): string;
		public function getDisplayName(): string;
	}
}

if (!interface_exists(IUserSession::class)) {
	interface IUserSession
	{
		public function getUser();
		public function isLoggedIn(): bool;
	}
}

if (!interface_exists(IGroupManager::class)) {
	interface IGroupManager
	{
		public function isAdmin(string $userId): bool;
	}
}

if (!interface_exists(IURLGenerator::class)) {
	interface IURLGenerator
	{
		public function linkToRoute(string $routeName, array $arguments = []): string;
		public function linkToRouteAbsolute(string $routeName, array $arguments = []): string;
		public function getAbsoluteURL(string $url): string;
	}
}

namespace Psr\Log;

if (!interface_exists(LoggerInterface::class)) {
	interface LoggerInterface
	{
		public function emergency(string $message, array $context = []): void;
		public function alert(string $message, array $context = []): void;
		public function critical(string $message, array $context = []): void;
		public function error(string $message, array $context = []): void;
		public function warning(string $message, array $context = []): void;
		public function notice(string $message, array $context = []): void;
		public function info(string $message, array $context = []): void;
		public function debug(string $message, array $context = []): void;
		public function log($level, string $message, array $context = []): void;
	}
}
