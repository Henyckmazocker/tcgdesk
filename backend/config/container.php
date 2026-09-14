<?php

declare(strict_types=1);

use App\Cli\CommandRegistry;
use App\Domain\GoogleAuthClientInterface;
use App\Domain\Import\ParserRegistry;
use App\Domain\Repository\AssumedPrintingRepositoryInterface;
use App\Domain\Repository\CardNameIndexRepositoryInterface;
use App\Domain\Repository\CardRepositoryInterface;
use App\Domain\Repository\CardResolutionRepositoryInterface;
use App\Domain\Repository\CatalogRepositoryInterface;
use App\Domain\Repository\CollectionRepositoryInterface;
use App\Domain\Repository\DeckRepositoryInterface;
use App\Domain\Repository\FollowRepositoryInterface;
use App\Domain\Repository\FriendshipRepositoryInterface;
use App\Domain\Repository\ImageCacheRepositoryInterface;
use App\Domain\Repository\PreconRepositoryInterface;
use App\Domain\Repository\PriceRepositoryInterface;
use App\Domain\Repository\TransactionManagerInterface;
use App\Domain\Repository\UserPrivacyRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;
use App\Infrastructure\Auth\GoogleAuthClient;
use App\Infrastructure\Database\DatabaseConnector;
use App\Infrastructure\Import\ArchidektCsvParser;
use App\Infrastructure\Import\ManaBoxCsvParser;
use App\Infrastructure\Import\MoxfieldCsvParser;
use App\Infrastructure\Import\PlainTextParser;
use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\Mtgjson\MtgJsonDownloader;
use App\Infrastructure\Persistence\MySqlAssumedPrintingRepository;
use App\Infrastructure\Persistence\MySqlCardNameIndexRepository;
use App\Infrastructure\Persistence\MySqlCardRepository;
use App\Infrastructure\Persistence\MySqlCardResolutionRepository;
use App\Infrastructure\Persistence\MySqlCatalogRepository;
use App\Infrastructure\Persistence\MySqlCollectionRepository;
use App\Infrastructure\Persistence\MySqlDeckRepository;
use App\Infrastructure\Persistence\MySqlFollowRepository;
use App\Infrastructure\Persistence\MySqlFriendshipRepository;
use App\Infrastructure\Persistence\MySqlImageCacheRepository;
use App\Infrastructure\Persistence\MySqlPreconRepository;
use App\Infrastructure\Persistence\MySqlPriceRepository;
use App\Infrastructure\Persistence\MySqlUserPrivacyRepository;
use App\Infrastructure\Persistence\MySqlUserRepository;
use App\Infrastructure\Persistence\PdoTransactionManager;
use App\Infrastructure\Persistence\Search\BooleanExpressionBuilder;
use App\Infrastructure\RateLimit\FileRateLimitStore;
use App\Infrastructure\Scryfall\ScryfallImageDownloader;
use App\Router\ActionRouter;
use DI\ContainerBuilder;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Definición del contenedor PHP-DI.
 *
 * Devuelve una FACTORÍA, no el contenedor: así cada entrypoint decide cuándo
 * construirlo. Lo consumen los dos:
 *   - public/index.php  (vía App\Application)
 *   - bin/tcgdesk       (la capa CLI del M3)
 *
 * Que ambos compartan este fichero es lo que hace que los use cases de ingesta
 * sean use cases normales y no un apaño paralelo al HTTP.
 */
return function (): ContainerInterface {
    $builder = new ContainerBuilder();

    // Autowiring por defecto; compilación solo en producción.
    if (($_ENV['APP_ENV'] ?? 'development') === 'production') {
        $builder->enableCompilation(__DIR__ . '/../storage/cache/di');
    }

    $builder->addDefinitions([

        // ====================================================================
        // INFRAESTRUCTURA
        // ====================================================================

        PDO::class => function (): PDO {
            return (new DatabaseConnector())->getConnection();
        },

        LoggerInterface::class => function (): LoggerInterface {
            return LoggerFactory::create('api');
        },

        FileRateLimitStore::class => function (LoggerInterface $logger): FileRateLimitStore {
            $dir = $_ENV['RATE_LIMIT_PATH'] ?? __DIR__ . '/../storage/ratelimit';
            return new FileRateLimitStore($dir, $logger);
        },

        // Los 177 MB de AllPrintings.json.gz caen aquí. storage/ está gitignored
        // entero, así que no hay riesgo de que acabe en un commit.
        MtgJsonDownloader::class => function (LoggerInterface $logger): MtgJsonDownloader {
            $dir = $_ENV['MTGJSON_PATH'] ?? __DIR__ . '/../storage/mtgjson';
            return new MtgJsonDownloader($logger, $dir);
        },

        // Las imágenes de la colección caen bajo storage/ también, y por eso
        // `mtg_image_cache.local_path` guarda una ruta RELATIVA a este
        // directorio: mover storage/ o montar el proyecto en otra ruta del
        // contenedor no invalida la tabla entera.
        ScryfallImageDownloader::class => function (
            ClientInterface $http,
            LoggerInterface $logger
        ): ScryfallImageDownloader {
            $dir = $_ENV['STORAGE_PATH'] ?? __DIR__ . '/../storage';
            return new ScryfallImageDownloader($http, $logger, $dir);
        },

        // ====================================================================
        // ROUTER
        // ====================================================================

        'routes' => require __DIR__ . '/routes.php',

        ActionRouter::class => function (ContainerInterface $c): ActionRouter {
            return new ActionRouter($c->get('routes'), $c, $c->get(LoggerInterface::class));
        },

        // ====================================================================
        // CAPA CLI
        // ====================================================================
        // Se define aquí, en el contenedor compartido, y NO en un bootstrap
        // propio: es lo que hace que los comandos de ingesta sean use cases
        // normales en vez de un backend paralelo al HTTP.

        'commands' => require __DIR__ . '/commands.php',

        CommandRegistry::class => function (ContainerInterface $c): CommandRegistry {
            return new CommandRegistry($c, $c->get(LoggerInterface::class), $c->get('commands'));
        },

        // ====================================================================
        // AUTENTICACIÓN
        // ====================================================================

        ClientInterface::class => DI\create(GuzzleClient::class),

        GoogleAuthClientInterface::class => DI\get(GoogleAuthClient::class),

        // ====================================================================
        // REPOSITORIOS — interfaz → implementación
        // ====================================================================
        // Todo lo demás (controllers, middlewares, comandos) lo resuelve el
        // autowiring de PHP-DI y no hace falta declararlo.

        UserRepositoryInterface::class    => DI\get(MySqlUserRepository::class),

        // Qué se ve de un usuario. Puerto aparte del de usuarios aunque la tabla
        // cuelgue de `users.id`: la fila de privacidad puede NO EXISTIR mientras
        // el usuario sí —la ausencia significa «los cinco defectos»—, y quien
        // decide a partir de esos niveles es `App\Domain\Social\Visibilidad`, no
        // el que lee el usuario. Ningún use case pregunta aquí directamente.
        UserPrivacyRepositoryInterface::class => DI\get(MySqlUserPrivacyRepository::class),

        // Quién es amigo de quién. Puerto aparte del de usuarios y del de
        // privacidad porque responde a otra pregunta —quién ERES tú para mí, no
        // qué enseñas— y porque de él cuelga la seguridad entera del
        // Plan - Amigos y Seguimiento: `App\Domain\Social\Visibilidad` resuelve
        // el nivel `friends` preguntando aquí y en ningún otro sitio.
        //
        // **Esta línea es lo que hace que la amistad funcione en producción, y no
        // solo en los tests.** `Visibilidad` NO está declarada en este fichero:
        // la autoinyecta PHP-DI al montar `PublicHttpRouter`, y el autowiring
        // SE SALTA los parámetros opcionales. Mientras esta interfaz no tuvo
        // implementación (M0 y M1), su parámetro en el constructor de
        // `Visibilidad` tuvo que ser opcional para que el contenedor no
        // intentara instanciar una interfaz y tumbara toda ruta pública; el M2,
        // al registrarla aquí, le quitó el `= null`. Si alguien vuelve a poner
        // un defecto en ese parámetro, PHP-DI lo saltará, `$this->amistades`
        // será `null` en producción y el nivel `friends` dejará de consultar
        // nada — con la suite en verde, porque allí el doble se pasa a mano.
        //
        // `user_follow` NO se sirve desde este puerto: es otra tabla, otro hito
        // y no da ningún acceso.
        FriendshipRepositoryInterface::class => DI\get(MySqlFriendshipRepository::class),

        // Quién sigue a quién. **Puerto aparte del de amistad a propósito, y
        // esta separación ES el M3 del Plan - Amigos y Seguimiento.** Seguir es
        // unilateral, no se le pide permiso a nadie y NO DA NINGÚN ACCESO: un
        // marcador sobre un perfil público, para volver a él sin buscarlo.
        //
        // Fíjate en quién NO recibe esto: `App\Domain\Social\Visibilidad` no
        // lo pide en su constructor, `PublicHttpRouter` tampoco, y ninguna
        // consulta que decida si algo se enseña llega hasta aquí. El día que
        // alguna lo haga, el nivel `friends` pasará a significar «cualquiera que
        // pulse seguir», o sea `everyone`, y los dos datos que el plan del
        // perfil público protege —cuánto vale tu colección y qué te falta— se
        // habrán abierto sin que nadie lo decidiera. Lo único que lee este
        // puerto es `FollowController` a través de sus tres use cases.
        FollowRepositoryInterface::class => DI\get(MySqlFollowRepository::class),

        CatalogRepositoryInterface::class => DI\get(MySqlCatalogRepository::class),

        CardRepositoryInterface::class    => DI\get(MySqlCardRepository::class),

        // Resolución de importación: solo lectura, y separada de la búsqueda de
        // usuario porque lo que le importa de un resultado es cuántos hay.
        CardResolutionRepositoryInterface::class => DI\get(MySqlCardResolutionRepository::class),

        // El único puerto que escribe en el catálogo sin ser la ingesta. Lo usa
        // `catalog:normalize` y nada más.
        CardNameIndexRepositoryInterface::class  => DI\get(MySqlCardNameIndexRepository::class),

        // La edición asumida de M5: qué impresión se escribe cuando el fichero
        // dice qué carta es pero no de qué edición. Puerto aparte y no un quinto
        // método del resolvedor porque no resuelve nada — la carta ya está
        // identificada— y porque necesita los PRECIOS, que el resolvedor no mira.
        AssumedPrintingRepositoryInterface::class => DI\get(MySqlAssumedPrintingRepository::class),

        PriceRepositoryInterface::class   => DI\get(MySqlPriceRepository::class),

        CollectionRepositoryInterface::class => DI\get(MySqlCollectionRepository::class),

        // Los mazos. Puerto aparte del de colección aunque `deleteConCartas()`
        // escriba en `mtg_collection_item`: son dos agregados distintos —un mazo
        // reclama cartas, no las contiene— y el acople entre los dos se CALCULA,
        // no se referencia. Ver `DeckRepositoryInterface`.
        DeckRepositoryInterface::class => DI\get(MySqlDeckRepository::class),

        ImageCacheRepositoryInterface::class => DI\get(MySqlImageCacheRepository::class),

        // Los precons de MTGJSON. Puerto aparte del catálogo y de los mazos
        // aunque las tres escriban tablas `mtg_*`: no se ingieren del mismo
        // fichero que el catálogo, y no son dato de usuario como `mtg_deck` —son
        // zona 1, se reconstruyen relanzando `decks:import` y por eso ni siquiera
        // llevan `user_id`. Ver `PreconRepositoryInterface`.
        PreconRepositoryInterface::class => DI\get(MySqlPreconRepository::class),

        // La transacción que cruza DOS puertos, y solo por eso existe: M7 pide
        // que `import_apply` cree el mazo **en la misma transacción** en la que
        // escribe la colección, y esas son dos interfaces distintas. Dentro de
        // un mismo agregado la transacción sigue siendo del repositorio.
        TransactionManagerInterface::class => DI\get(PdoTransactionManager::class),

        // ====================================================================
        // IMPORTACIÓN — parsers de colección
        // ====================================================================
        // El ORDEN de este array es la política de detección: gana el primero
        // que reconoce el fichero. Los parsers con cabecera propia van delante
        // y `PlainTextParser` va SIEMPRE el último, porque es el único que
        // podría reclamar cualquier cosa: su `supports()` exige que la mitad de
        // las líneas útiles traigan cantidad, pero un CSV con una columna de
        // cantidad al principio no debe llegar nunca a preguntárselo.

        ParserRegistry::class => function (ContainerInterface $c): ParserRegistry {
            return new ParserRegistry([
                $c->get(ManaBoxCsvParser::class),
                $c->get(MoxfieldCsvParser::class),
                $c->get(ArchidektCsvParser::class),
                $c->get(PlainTextParser::class),
            ]);
        },

        // Las stopwords y el tamaño mínimo de token se leen del SERVIDOR, no se
        // copian aquí: son configuración de MySQL, y una copia a mano se
        // desincroniza en silencio y deja búsquedas devolviendo cero resultados
        // sin ningún error. Es exactamente el fallo que midió el M0 del
        // Plan - Mirror del Catálogo MTG con 'Jace, the Mind Sculptor'.
        BooleanExpressionBuilder::class => function (PDO $db): BooleanExpressionBuilder {
            $stopwords = $db
                ->query('SELECT value FROM information_schema.INNODB_FT_DEFAULT_STOPWORD')
                ->fetchAll(PDO::FETCH_COLUMN);

            $minToken = (int) ($db
                ->query("SHOW VARIABLES LIKE 'innodb_ft_min_token_size'")
                ->fetch()['Value'] ?? 3);

            return new BooleanExpressionBuilder(array_fill_keys($stopwords, true), $minToken);
        },

    ]);

    return $builder->build();
};
