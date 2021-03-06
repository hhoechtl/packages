<?php

namespace App\Command;

use App\Components\Api\Client;
use App\Components\PluginReader;
use App\Entity\Package;
use App\Entity\Producer;
use App\Entity\Version;
use App\Repository\PackageRepository;
use App\Repository\ProducerRepository;
use App\Struct\ComposerPackageVersion;
use App\Struct\License\Binaries;
use App\Struct\License\Plugin;
use Composer\Semver\VersionParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class InternalPackageImportCommand extends Command
{
    protected static $defaultName = 'internal:package:import';

    /**
     * @var HttpClientInterface
     */
    private $client;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var PackageRepository
     */
    private $packageRepository;

    /**
     * @var ProducerRepository
     */
    private $producerRepository;

    /**
     * @var VersionParser
     */
    private $versionParser;

    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->packageRepository = $entityManager->getRepository(Package::class);
        $this->producerRepository = $entityManager->getRepository(Producer::class);
        $this->versionParser = new VersionParser();
    }

    public function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED)
            ->addArgument('password', InputArgument::REQUIRED)
            ->addOption('offset', 'o', InputOption::VALUE_OPTIONAL, 'Offset', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->login($input);

        $current = (int) $input->getOption('offset');
        $plugins = $this->loadPlugins($current);

        $progressBar = new ProgressBar($output);
        $progressBar->start();

        while (!empty($plugins)) {
            /** @var Plugin $plugin */
            foreach ($plugins as $plugin) {
                $this->processPlugin($plugin);
                $progressBar->advance();
            }

            $this->entityManager->flush();
            $this->entityManager->clear();
            $current += count($plugins);
            $plugins = $this->loadPlugins($current);
            $this->login($input);
        }

        $progressBar->finish();
    }

    private function login(InputInterface $input): void
    {
        $client = HttpClient::create();

        $response = $client->request('POST', getenv('SBP_LOGIN'), [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'name' => $input->getArgument('username'),
                'password' => $input->getArgument('password'),
            ],
        ])->toArray();

        $this->client = HttpClient::create([
            'headers' => [
                'X-Shopware-Token' => $response['token'],
                'User-Agent' => 'packages.friendsofshopware.de',
            ],
        ]);
    }

    private function loadPlugins(int $offset): array
    {
        return json_decode($this->client->request('GET', getenv('SBP_PLUGIN_LIST'), [
            'query' => [
                'filter' => '[{"property":"approvalStatus","value":"approved","operator":"="},{"property":"activationStatus","value":"activated","operator":"="}]',
                'limit' => 100,
                'offset' => $offset,
                'orderby' => 'id',
                'ordersequence' => 'desc',
            ],
        ])->getContent());
    }

    /**
     * @param Plugin $plugin
     */
    private function processPlugin($plugin): void
    {
        // Don't trigger an error on api server, cause this is an fake plugin
        if ($plugin->name === 'SwagCorePlatform') {
            return;
        }

        $package = $this->packageRepository->findOneBy([
            'name' => $plugin->name,
        ]);

        if (!$package) {
            $package = new Package();
            $package->setName($plugin->name);

            $producer = $this->producerRepository->findOneBy(['name' => $plugin->producer->name]);

            if (!$producer) {
                $producer = new Producer();
                $producer->setName($plugin->producer->name);
                $this->entityManager->persist($producer);
                $this->entityManager->flush();
            }

            $package->setProducer($producer);

            $this->entityManager->persist($package);
        }

        $package->setReleaseDate(new \DateTime($plugin->creationDate));
        $package->setStoreLink('https://store.shopware.com/search?sSearch=' . $plugin->code);

        foreach ($plugin->infos as $info) {
            if ('en_GB' === $info->locale->name) {
                $package->setDescription($info->description);
                $package->setDescription($package->getSafeDescription());
                $package->setShortDescription($info->shortDescription);
            }
        }

        $this->entityManager->flush();

        if (!is_iterable($plugin->binaries)) {
            return;
        }

        /** @var Binaries $binary */
        foreach ($plugin->binaries as $binary) {
            try {
                $this->versionParser->normalize($binary->version);
            } catch (\UnexpectedValueException $e) {
                // Very old version
                if ($binary->creationDate === null) {
                    continue;
                }

                // Plugin developer does not understand semver
                $createDate = new \DateTime($binary->creationDate);
                $binary->version = $createDate->format('Y.m.d-Hi');
            }

            $foundVersion = false;
            foreach ($package->getVersions() as $version) {
                if ($version->getVersion() === $binary->version) {
                    if ('codereviewsucceeded' !== $binary->status->name) {
                        $this->entityManager->remove($version);
                        $this->entityManager->flush();
                    }
                    $foundVersion = $version;
                }
            }

            if ($foundVersion) {
                $foundVersion->setReleaseDate(new \DateTime($binary->creationDate));

                foreach ($binary->changelogs as $changelog) {
                    if ('en_GB' === $changelog->locale->name) {
                        $foundVersion->setChangelog($changelog->text);
                    }
                }

                continue;
            }

            if ('codereviewsucceeded' !== $binary->status->name) {
                continue;
            }

            if (empty($binary->compatibleSoftwareVersions)) {
                continue;
            }

            $composerPackageVersion = new ComposerPackageVersion();
            $composerPackageVersion->name = $package->getComposerName();
            $composerPackageVersion->version = $binary->version;
            $composerPackageVersion->type = 'shopware-plugin';
            $composerPackageVersion->extra = [
                'installer-name' => $plugin->name,
            ];
            $composerPackageVersion->require = [
                'composer/installers' => '~1.0',
            ];
            $composerPackageVersion->authors = [
                [
                    'name' => $plugin->producer->name,
                ],
            ];

            if ('classic' === $plugin->generation->name) {
                $composerPackageVersion->require['shopware/shopware'] = '>=' . $binary->compatibleSoftwareVersions[0]->name;
            }

            try {
                $pluginZip = $this->client->request('GET', Client::ENDPOINT . 'plugins/' . $plugin->id . '/binaries/' . $binary->id . '/file', [
                    'query' => [
                        'unencrypted' => 'true',
                    ],
                ])->getContent();
            } catch (ServerException $e) {
                if (500 === $e->getCode()) {
                    // Some binaries are broken
                    continue;
                }

                throw $e;
            }

            try {
                $info = array_merge(get_object_vars($composerPackageVersion), PluginReader::readFromZip($pluginZip));
            } catch (\InvalidArgumentException $e) {
                continue;
            }

            foreach ($info as $k => $v) {
                $composerPackageVersion->$k = $v;
            }

            $version = new Version();
            $version->setVersion($binary->version);
            $version->setType($composerPackageVersion->type);
            $version->setLicense($composerPackageVersion->license);
            $version->setHomepage($composerPackageVersion->homepage);
            $version->setDescription(mb_substr($composerPackageVersion->description, 0, 255));
            $version->setExtra($composerPackageVersion->extra);
            $version->setRequireSection($composerPackageVersion->require);
            $version->setAuthors($composerPackageVersion->authors);
            $version->setPackage($package);
            $version->setReleaseDate(new \DateTime($binary->creationDate));
            $package->addVersion($version);

            foreach ($binary->changelogs as $changelog) {
                if ('en_GB' === $changelog->locale->name) {
                    $version->setChangelog($changelog->text);
                }
            }

            $this->entityManager->persist($version);
        }

        if ($package->getVersions()->count() === 0) {
            $this->entityManager->remove($package);
        }

        $this->entityManager->flush();
    }
}
