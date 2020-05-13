<?php

declare(strict_types=1);

namespace EonX\EasyCfhighlander\Console\Commands;

use EonX\EasyCfhighlander\File\File;
use EonX\EasyCfhighlander\File\FileStatus;
use EonX\EasyCfhighlander\Interfaces\FileGeneratorInterface;
use EonX\EasyCfhighlander\Interfaces\ManifestGeneratorInterface;
use EonX\EasyCfhighlander\Interfaces\ParameterResolverInterface;
use Nette\Utils\Strings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

abstract class AbstractTemplatesCommand extends Command
{
    /**
     * @var string[]
     */
    private const CHECKS = [
        'elasticsearch_enabled' => 'elasticsearch',
        'redis_enabled' => 'redis',
    ];

    /**
     * @var int
     */
    public const EXIT_CODE_ERROR = 1;

    /**
     * @var int
     */
    public const EXIT_CODE_SUCCESS = 0;

    /**
     * @var \EonX\EasyCfhighlander\Interfaces\FileGeneratorInterface
     */
    private $fileGenerator;

    /**
     * @var \Symfony\Component\Filesystem\Filesystem
     */
    private $filesystem;

    /**
     * @var \EonX\EasyCfhighlander\Interfaces\ManifestGeneratorInterface
     */
    private $manifestGenerator;

    /**
     * @var \EonX\EasyCfhighlander\Interfaces\ParameterResolverInterface
     */
    private $parameterResolver;

    public function __construct(
        FileGeneratorInterface $fileGenerator,
        Filesystem $filesystem,
        ManifestGeneratorInterface $manifestGenerator,
        ParameterResolverInterface $parameterResolver
    ) {
        parent::__construct();

        $this->fileGenerator = $fileGenerator;
        $this->filesystem = $filesystem;
        $this->manifestGenerator = $manifestGenerator;
        $this->parameterResolver = $parameterResolver;
    }

    /**
     * @return string[]
     */
    abstract protected function getProjectFiles(): array;

    /**
     * @return string[]
     */
    abstract protected function getSimpleFiles(): array;

    abstract protected function getTemplatePrefix(): string;

    protected function configure(): void
    {
        $this->addOption('cwd', null, InputOption::VALUE_OPTIONAL, 'Current working directory', \getcwd());
    }

    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $style = new SymfonyStyle($input, $output);
        /** @var string $cwd */
        $cwd = $input->getOption('cwd') ?? \getcwd();
        $easyDirectory = $this->getEasyDirectory($cwd);

        foreach ($this->getParamResolvers($style) as $param => $resolver) {
            $this->parameterResolver->addResolver($param, $resolver);
        }
        foreach ($this->getParamModifiers() as $param => $modifier) {
            $this->parameterResolver->addModifier($param, $modifier);
        }

        $params = $this->parameterResolver
            ->setCachePathname(\sprintf('%s/easy-cfhighlander-params.yaml', $easyDirectory))
            ->resolve($input);

        $files = [];
        foreach ($this->getProjectFiles() as $file) {
            $files[] = $this->getProjectFileToGenerate($cwd, $file, $params['project']);
        }

        foreach ($this->getSimpleFiles() as $file) {
            $files[] = $this->getSimpleFileToGenerate($cwd, $file);
        }

        ProgressBar::setFormatDefinition('custom', ' %current%/%max% [%bar%] %percent:3s%% %message%');
        $progress = new ProgressBar($style, \count($files));
        $progress->setFormat('custom');
        $progress->setOverwrite(false);

        if ($this->filesystem->exists($cwd) === false) {
            $this->filesystem->mkdir($cwd);
        }

        // Create easy directory if required
        if ($this->filesystem->exists($easyDirectory) === false) {
            $this->filesystem->mkdir($easyDirectory);
        }

        $style->write(\sprintf("Generating files in <comment>%s</comment>:\n", \realpath($cwd)));

        $statuses = [];

        foreach ($files as $file) {
            /** @var \EonX\EasyCfhighlander\File\File $file */
            $statuses[] = $status = $this->processFile($file, $params);

            $progress->setMessage(\sprintf(
                '- <comment>%s</comment> <info>%s</info>...',
                $status->getStatus(),
                $file->getFilename()
            ));
            $progress->advance();
        }

        /** @var \Symfony\Component\Console\Application $app */
        $app = $this->getApplication();

        $this->manifestGenerator->generate($easyDirectory, $app->getVersion(), $statuses);

        return self::EXIT_CODE_SUCCESS;
    }

    protected function getAlphaParamValidator(): \Closure
    {
        return static function ($answer): string {
            if (empty($answer)) {
                throw new \RuntimeException('A value is required');
            }

            if (\ctype_alpha($answer) === false) {
                throw new \RuntimeException('Value must be strictly alphabetic');
            }

            return \str_replace(' ', '', (string)$answer);
        };
    }

    /**
     * @param null|mixed $param
     */
    protected function getBooleanParamAsString($param = null): string
    {
        return (bool)($param) ? 'true' : 'false';
    }

    protected function getBooleanParamValidator(): \Closure
    {
        return static function ($answer): bool {
            if (empty($answer)) {
                return false;
            }

            $answer = \strtolower((string)$answer);

            if (\in_array($answer, ['true', 'false'], true) === false) {
                throw new \RuntimeException('The value must be either empty, or a string "true" or "false"');
            }

            return $answer === 'true';
        };
    }

    /**
     * @return iterable<string, callable>
     */
    protected function getParamModifiers(): iterable
    {
        // No body needed for now.

        return [];
    }

    /**
     * @return iterable<string, callable>
     */
    protected function getParamResolvers(SymfonyStyle $style): iterable
    {
        $alpha = $this->getAlphaParamValidator();
        $boolean = $this->getBooleanParamValidator();
        $required = $this->getRequiredParamValidator();

        // Project name
        yield 'project' => function (array $params) use ($style, $required): string {
            return $style->ask('Project name', $this->getAsStringOrNull($params['project'] ?? null), $required);
        };

        // Database name
        yield 'db_name' => function (array $params) use ($style, $alpha): string {
            return $style->ask('Database name', $this->getAsStringOrNull($params['db_name'] ?? null), $alpha);
        };

        // Database username
        yield 'db_username' => function (array $params) use ($style, $alpha): string {
            return $style->ask(
                'Database username',
                $this->getAsStringOrNull($params['db_username'] ?? $params['db_name'] ?? null),
                $alpha
            );
        };

        // DNS domain
        yield 'dns_domain' => function (array $params) use ($style, $required): string {
            return $style->ask('DNS domain', $this->getAsStringOrNull($params['dns_domain'] ?? null), $required);
        };

        // Redis enabled
        yield 'redis_enabled' => function (array $params) use ($style, $boolean): bool {
            return $style->ask(
                'Redis enabled',
                $this->getBooleanParamAsString($params['redis_enabled'] ?? null),
                $boolean
            );
        };

        // Elasticsearch enabled
        yield 'elasticsearch_enabled' => function (array $params) use ($style, $boolean): bool {
            return $style->ask(
                'Elasticsearch enabled',
                $this->getBooleanParamAsString($params['elasticsearch_enabled'] ?? null),
                $boolean
            );
        };

        // SSM Prefix
        yield 'ssm_prefix' => function (array $params) use ($style, $alpha): string {
            return $style->ask(
                'SSM Prefix',
                $this->getAsStringOrNull($params['ssm_prefix'] ?? $params['project'] ?? null),
                $alpha
            );
        };

        // SQS Queue
        yield 'sqs_queue' => function (array $params) use ($style, $alpha): string {
            return $style->ask(
                'SQS Queue',
                $this->getAsStringOrNull($params['sqs_queue'] ?? $params['project'] ?? null),
                $alpha
            );
        };

        // AWS DEV Account
        yield 'dev_account' => function (array $params) use ($style, $required): string {
            return $style->ask('AWS DEV Account', $this->getAsStringOrNull($params['dev_account'] ?? null), $required);
        };

        // AWS OPS Account
        yield 'ops_account' => function (array $params) use ($style, $required): string {
            return $style->ask('AWS OPS Account', $this->getAsStringOrNull($params['ops_account'] ?? null), $required);
        };

        // AWS PROD Account
        yield 'prod_account' => function (array $params) use ($style, $required): string {
            return $style->ask(
                'AWS PROD Account',
                $this->getAsStringOrNull($params['prod_account'] ?? null),
                $required
            );
        };

        // CLI ECS Task (on EC2)
        yield 'cli_enabled' => function (array $params) use ($style, $boolean): bool {
            return $style->ask(
                'CLI enabled',
                $this->getBooleanParamAsString($params['cli_enabled'] ?? null),
                $boolean
            );
        };
    }

    protected function getProjectFileToGenerate(string $cwd, string $name, string $project): File
    {
        $filename = \sprintf('%s/%s', $cwd, $name);

        return new File(\str_replace('project', $project, $filename), $this->getTemplateName($name));
    }

    protected function getRequiredParamValidator(): \Closure
    {
        return static function ($answer): string {
            if (empty($answer)) {
                throw new \RuntimeException('A value is required');
            }

            return \str_replace(' ', '', (string)$answer);
        };
    }

    protected function getSimpleFileToGenerate(string $cwd, string $name): File
    {
        return new File(\sprintf('%s/%s', $cwd, $name), $this->getTemplateName($name));
    }

    protected function getTemplateName(string $template): string
    {
        return \sprintf('%s/%s.twig', $this->getTemplatePrefix(), $template);
    }

    /**
     * @param mixed $value
     */
    private function getAsStringOrNull($value): ?string
    {
        return $value === null ? null : (string)$value;
    }

    private function getEasyDirectory(string $cwd): string
    {
        // If easy-cfhighlander* file already exists in cwd, .easy directory will not be used/created
        if (\file_exists($cwd . \DIRECTORY_SEPARATOR . 'easy-cfhighlander-params.yaml') === true) {
            return $cwd;
        }

        // Otherwise return path to .easy directory
        return \implode(\DIRECTORY_SEPARATOR, [$cwd, '.easy']);
    }

    /**
     * @param mixed[] $params
     */
    private function processFile(File $file, array $params): FileStatus
    {
        foreach (self::CHECKS as $param => $path) {
            $check = (bool)($params[$param] ?? false);

            if ($check === false && Strings::contains($file->getFilename(), $path)) {
                return $this->fileGenerator->remove($file);
            }
        }

        return $this->fileGenerator->generate($file, $params);
    }
}
