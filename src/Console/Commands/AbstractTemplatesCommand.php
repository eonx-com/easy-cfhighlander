<?php
declare(strict_types=1);

namespace LoyaltyCorp\EasyCfhighlander\Console\Commands;

use LoyaltyCorp\EasyCfhighlander\File\FileToGenerate;
use LoyaltyCorp\EasyCfhighlander\Interfaces\FileGeneratorInterface;
use LoyaltyCorp\EasyCfhighlander\Interfaces\ManifestGeneratorInterface;
use LoyaltyCorp\EasyCfhighlander\Interfaces\ParameterResolverInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

abstract class AbstractTemplatesCommand extends Command
{
    public const EXIT_CODE_ERROR = 1;
    public const EXIT_CODE_SUCCESS = 0;

    /** @var \LoyaltyCorp\EasyCfhighlander\Interfaces\FileGeneratorInterface */
    private $fileGenerator;

    /** @var \Symfony\Component\Filesystem\Filesystem */
    private $filesystem;

    /** @var \LoyaltyCorp\EasyCfhighlander\Interfaces\ManifestGeneratorInterface */
    private $manifestGenerator;

    /** @var \LoyaltyCorp\EasyCfhighlander\Interfaces\ParameterResolverInterface */
    private $parameterResolver;

    /**
     * AbstractTemplatesCommand constructor.
     *
     * @param \LoyaltyCorp\EasyCfhighlander\Interfaces\FileGeneratorInterface $fileGenerator
     * @param \Symfony\Component\Filesystem\Filesystem $filesystem
     * @param \LoyaltyCorp\EasyCfhighlander\Interfaces\ManifestGeneratorInterface $manifestGenerator
     * @param \LoyaltyCorp\EasyCfhighlander\Interfaces\ParameterResolverInterface $parameterResolver
     */
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
     * Get project files names.
     *
     * @return string[]
     */
    abstract protected function getProjectFiles(): array;

    /**
     * Get simple files names.
     *
     * @return string[]
     */
    abstract protected function getSimpleFiles(): array;

    /**
     * Get template prefix.
     *
     * @return string
     */
    abstract protected function getTemplatePrefix(): string;

    /**
     * Add parameters resolver.
     *
     * @param \Symfony\Component\Console\Style\SymfonyStyle $style
     *
     * @return void
     */
    protected function addParamResolver(SymfonyStyle $style): void
    {
        $this->parameterResolver->addResolver(function (array $params) use ($style): array {
            $alpha = $this->getAlphaParamValidator();
            $required = $this->getRequiredParamValidator();

            return [
                'project' => $style->ask('Project name', $params['project'] ?? null, $required),
                'db_name' => $style->ask('Database name', $params['db_name'] ?? null, $alpha),
                'db_username' => $style->ask('Database username', $params['db_username'] ?? null, $alpha),
                'dns_domain' => $style->ask('DNS domain', $params['dns_domain'] ?? null, $required),
                'dev_account' => $style->ask('AWS DEV account', $params['dev_account'] ?? null, $required),
                'ops_account' => $style->ask('AWS OPS account', $params['ops_account'] ?? null, $required),
                'prod_account' => $style->ask('AWS PROD account', $params['prod_account'] ?? null, $required)
            ];
        });
    }

    /**
     * Configure command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->addOption('cwd', null, InputOption::VALUE_OPTIONAL, 'Current working directory', \getcwd());
    }

    /**
     * Execute command.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return null|int
     */
    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $style = new SymfonyStyle($input, $output);
        $this->addParamResolver($style);

        $cwd = $input->getOption('cwd') ?? \getcwd();
        $params = $this->parameterResolver
            ->setCachePathname(\sprintf('%s/easy-cfhighlander-params.yaml', $cwd))
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

        $style->write(\sprintf("Generating files in <comment>%s</comment>:\n", \realpath($cwd)));

        $statuses = [];

        foreach ($files as $file) {
            /** @var \LoyaltyCorp\EasyCfhighlander\File\FileToGenerate $file */
            $statuses[] = $status = $this->fileGenerator->generate($file, $params);

            $progress->setMessage(\sprintf(
                '- <comment>%s</comment> <info>%s</info>...',
                $status->getStatus(),
                $file->getFilename()
            ));
            $progress->advance();
        }

        $this->manifestGenerator->generate($cwd, $this->getApplication()->getVersion(), $statuses);

        return self::EXIT_CODE_SUCCESS;
    }

    /**
     * Get project file to generate.
     *
     * @param string $cwd
     * @param string $name
     * @param string $project
     *
     * @return \LoyaltyCorp\EasyCfhighlander\File\FileToGenerate
     */
    protected function getProjectFileToGenerate(string $cwd, string $name, string $project): FileToGenerate
    {
        $filename = \sprintf('%s/%s', $cwd, $name);

        return new FileToGenerate(\str_replace('project', $project, $filename), $this->getTemplateName($name));
    }

    /**
     * Get file to generate for given name.
     *
     * @param string $cwd
     * @param string $name
     *
     * @return \LoyaltyCorp\EasyCfhighlander\File\FileToGenerate
     */
    protected function getSimpleFileToGenerate(string $cwd, string $name): FileToGenerate
    {
        return new FileToGenerate(\sprintf('%s/%s', $cwd, $name), $this->getTemplateName($name));
    }

    /**
     * Get template name for given template.
     *
     * @param string $template
     *
     * @return string
     */
    protected function getTemplateName(string $template): string
    {
        return \sprintf('%s/%s.twig', $this->getTemplatePrefix(), $template);
    }

    /**
     * Get validator for required alphabetic parameters.
     *
     * @return \Closure
     */
    private function getAlphaParamValidator(): \Closure
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
     * Get validator for required parameters.
     *
     * @return \Closure
     */
    private function getRequiredParamValidator(): \Closure
    {
        return static function ($answer): string {
            if (empty($answer)) {
                throw new \RuntimeException('A value is required');
            }

            return \str_replace(' ', '', (string)$answer);
        };
    }
}
