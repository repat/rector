<?php

declare(strict_types=1);

namespace Rector\Autodiscovery\Rector\FileNode;

use Controller;
use Nette\Utils\Strings;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Type\ObjectType;
use Rector\Autodiscovery\Analyzer\ValueObjectClassAnalyzer;
use Rector\Core\Contract\Rector\ConfigurableRectorInterface;
use Rector\Core\PhpParser\Node\CustomNode\FileNode;
use Rector\Core\Rector\AbstractRector;
use Rector\FileSystemRector\ValueObject\MovedFileWithNodes;
use Rector\FileSystemRector\ValueObjectFactory\MovedFileWithNodesFactory;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Inspiration @see https://github.com/rectorphp/rector/pull/1865/files#diff-0d18e660cdb626958662641b491623f8
 * @wip
 *
 * @see \Rector\Tests\Autodiscovery\Rector\FileNode\MoveValueObjectsToValueObjectDirectoryRector\MoveValueObjectsToValueObjectDirectoryRectorTest
 */
final class MoveValueObjectsToValueObjectDirectoryRector extends AbstractRector implements ConfigurableRectorInterface
{
    /**
     * @var string
     */
    public const TYPES = 'types';

    /**
     * @var string
     */
    public const SUFFIXES = 'suffixes';

    /**
     * @api
     * @var string
     */
    public const ENABLE_VALUE_OBJECT_GUESSING = 'enable_value_object_guessing';

    /**
     * @var string[]|class-string<Controller>[]
     */
    private const COMMON_SERVICE_SUFFIXES = [
        'Repository', 'Command', 'Mapper', 'Controller', 'Presenter', 'Factory', 'Test', 'TestCase', 'Service',
    ];

    /**
     * @var bool
     */
    private $enableValueObjectGuessing = true;

    /**
     * @var class-string[]
     */
    private $types = [];

    /**
     * @var string[]
     */
    private $suffixes = [];

    /**
     * @var MovedFileWithNodesFactory
     */
    private $movedFileWithNodesFactory;

    /**
     * @var ValueObjectClassAnalyzer
     */
    private $valueObjectClassAnalyzer;

    public function __construct(
        MovedFileWithNodesFactory $movedFileWithNodesFactory,
        ValueObjectClassAnalyzer $valueObjectClassAnalyzer
    ) {
        $this->movedFileWithNodesFactory = $movedFileWithNodesFactory;
        $this->valueObjectClassAnalyzer = $valueObjectClassAnalyzer;
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Move value object to ValueObject namespace/directory', [
            new ConfiguredCodeSample(
                <<<'CODE_SAMPLE'
// app/Exception/Name.php
class Name
{
    private $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getName()
    {
        return $this->name;
    }
}
CODE_SAMPLE
,
                <<<'CODE_SAMPLE'
// app/ValueObject/Name.php
class Name
{
    private $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getName()
    {
        return $this->name;
    }
}
CODE_SAMPLE
                ,
                [
                    self::TYPES => ['ValueObjectInterfaceClassName'],
                    self::SUFFIXES => ['Search'],
                    self::ENABLE_VALUE_OBJECT_GUESSING => true,
                ]
            ),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [FileNode::class];
    }

    /**
     * @param FileNode $node
     */
    public function refactor(Node $node): ?Node
    {
        $class = $this->betterNodeFinder->findFirstInstanceOf([$node], Class_::class);
        if (! $class instanceof Class_) {
            return null;
        }

        if (! $this->isValueObjectMatch($class)) {
            return null;
        }

        $smartFileInfo = $node->getFileInfo();
        $movedFileWithNodes = $this->movedFileWithNodesFactory->createWithDesiredGroup(
            $smartFileInfo,
            $node->stmts,
            'ValueObject'
        );

        if (! $movedFileWithNodes instanceof MovedFileWithNodes) {
            return null;
        }

        $this->removedAndAddedFilesCollector->addMovedFile($movedFileWithNodes);

        return null;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public function configure(array $configuration): void
    {
        $this->types = $configuration[self::TYPES] ?? [];
        $this->suffixes = $configuration[self::SUFFIXES] ?? [];
        $this->enableValueObjectGuessing = $configuration[self::ENABLE_VALUE_OBJECT_GUESSING] ?? false;
    }

    private function isValueObjectMatch(Class_ $class): bool
    {
        if ($this->isSuffixMatch($class)) {
            return true;
        }

        $className = $this->getName($class);
        if ($className === null) {
            return false;
        }

        $classObjectType = new ObjectType($className);

        foreach ($this->types as $type) {
            $desiredObjectType = new ObjectType($type);
            if ($desiredObjectType->isSuperTypeOf($classObjectType)->yes()) {
                return true;
            }
        }

        if ($this->isKnownServiceType($className)) {
            return false;
        }

        if (! $this->enableValueObjectGuessing) {
            return false;
        }

        return $this->valueObjectClassAnalyzer->isValueObjectClass($class);
    }

    private function isSuffixMatch(Class_ $class): bool
    {
        $className = $class->getAttribute(AttributeKey::CLASS_NAME);
        if ($className === null) {
            return false;
        }

        foreach ($this->suffixes as $suffix) {
            if (Strings::endsWith($className, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isKnownServiceType(string $className): bool
    {
        foreach (self::COMMON_SERVICE_SUFFIXES as $commonServiceSuffix) {
            if (Strings::endsWith($className, $commonServiceSuffix)) {
                return true;
            }
        }

        return false;
    }
}
