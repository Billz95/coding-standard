<?php declare(strict_types = 1);

namespace SlevomatCodingStandard\Sniffs\Namespaces;

use SlevomatCodingStandard\Helpers\NamespaceHelper;
use SlevomatCodingStandard\Helpers\ReferencedName;
use SlevomatCodingStandard\Helpers\ReferencedNameHelper;
use SlevomatCodingStandard\Helpers\TokenHelper;
use SlevomatCodingStandard\Helpers\UseStatement;
use SlevomatCodingStandard\Helpers\UseStatementHelper;

class UnusedUsesSniff implements \PHP_CodeSniffer\Sniffs\Sniff
{

	public const CODE_UNUSED_USE = 'UnusedUse';
	public const CODE_MISMATCHING_CASE = 'MismatchingCaseSensitivity';

	/** @var bool */
	public $searchAnnotations = false;

	/**
	 * @return mixed[]
	 */
	public function register(): array
	{
		return [
			T_OPEN_TAG,
		];
	}

	/**
	 * @phpcsSuppress SlevomatCodingStandard.TypeHints.TypeHintDeclaration.MissingParameterTypeHint
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @param int $openTagPointer
	 */
	public function process(\PHP_CodeSniffer\Files\File $phpcsFile, $openTagPointer): void
	{
		$unusedNames = UseStatementHelper::getUseStatements($phpcsFile, $openTagPointer);
		$referencedNames = ReferencedNameHelper::getAllReferencedNames($phpcsFile, $openTagPointer);

		foreach ($referencedNames as $referencedName) {
			$name = $referencedName->getNameAsReferencedInFile();
			$pointer = $referencedName->getStartPointer();
			$nameParts = NamespaceHelper::getNameParts($name);
			$nameAsReferencedInFile = $nameParts[0];
			$nameReferencedWithoutSubNamespace = count($nameParts) === 1;
			$uniqueId = $nameReferencedWithoutSubNamespace
				? UseStatement::getUniqueId($referencedName->getType(), $nameAsReferencedInFile)
				: UseStatement::getUniqueId(ReferencedName::TYPE_DEFAULT, $nameAsReferencedInFile);
			if (
				NamespaceHelper::isFullyQualifiedName($name)
				|| !isset($unusedNames[$uniqueId])
			) {
				continue;
			}

			if ($nameReferencedWithoutSubNamespace && !$referencedName->hasSameUseStatementType($unusedNames[$uniqueId])) {
				continue;
			}
			if ($unusedNames[$uniqueId]->getNameAsReferencedInFile() !== $nameAsReferencedInFile) {
				$phpcsFile->addError(sprintf(
					'Case of reference name %s and use statement %s do not match.',
					$nameAsReferencedInFile,
					$unusedNames[$uniqueId]->getNameAsReferencedInFile()
				), $pointer, self::CODE_MISMATCHING_CASE);
			}
			unset($unusedNames[$uniqueId]);
		}

		if ($this->searchAnnotations) {
			$tokens = $phpcsFile->getTokens();
			$searchAnnotationsPointer = $openTagPointer + 1;
			while (true) {
				$docCommentPointer = TokenHelper::findNext($phpcsFile, [T_DOC_COMMENT_TAG, T_DOC_COMMENT_STRING], $searchAnnotationsPointer);
				if ($docCommentPointer === null) {
					break;
				}

				foreach ($unusedNames as $i => $useStatement) {
					$nameAsReferencedInFile = $useStatement->getNameAsReferencedInFile();
					if (
						!preg_match('~^@' . preg_quote($nameAsReferencedInFile, '~') . '(?=[^a-z\\d]|$)~i', $tokens[$docCommentPointer]['content'])
						&& !preg_match('~(?<=^|[^a-z\\d\\\\])' . preg_quote($nameAsReferencedInFile, '~') . '(?=\\\\|[^a-z\\d]|$)~i', $tokens[$docCommentPointer]['content'])
					) {
						continue;
					}

					unset($unusedNames[$i]);
				}

				$searchAnnotationsPointer = $docCommentPointer + 1;
			}
		}

		foreach ($unusedNames as $value) {
			$fullName = $value->getFullyQualifiedTypeName();
			if ($value->getNameAsReferencedInFile() !== $fullName && $value->getNameAsReferencedInFile() !== NamespaceHelper::getUnqualifiedNameFromFullyQualifiedName($fullName)) {
				$fullName .= sprintf(' (as %s)', $value->getNameAsReferencedInFile());
			}
			$fix = $phpcsFile->addFixableError(sprintf(
				'Type %s is not used in this file.',
				$fullName
			), $value->getPointer(), self::CODE_UNUSED_USE);
			if (!$fix) {
				continue;
			}

			$phpcsFile->fixer->beginChangeset();
			$endPointer = TokenHelper::findNext($phpcsFile, T_SEMICOLON, $value->getPointer()) + 1;
			for ($i = $value->getPointer(); $i <= $endPointer; $i++) {
				$phpcsFile->fixer->replaceToken($i, '');
			}
			$phpcsFile->fixer->endChangeset();
		}
	}

}
