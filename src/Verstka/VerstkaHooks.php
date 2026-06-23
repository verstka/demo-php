<?php

declare(strict_types=1);

namespace App\Verstka;

use App\Config\Settings;
use App\Repo\ArticleRepo;
use App\Services\PublishService;
use App\Support\Validation;
use Verstka\Sdk\Finalize\ContentFinalizeContext;
use Verstka\Sdk\Finalize\ContentFinalizeResult;
use Verstka\Sdk\Finalize\ContentPreSaveContext;
use Verstka\Sdk\Finalize\FontsFinalizeContext;
use Verstka\Sdk\Finalize\FontsFinalizeResult;
use Verstka\Sdk\Finalize\FontsPreSaveContext;
use Verstka\Sdk\Finalize\PreSaveDecision;

final class VerstkaHooks
{
    public readonly \Closure $onContentPreSave;
    public readonly \Closure $onFontsPreSave;
    public readonly \Closure $onContentFinalize;
    public readonly \Closure $onFontsFinalize;

    public function __construct(
        Settings $settings,
        ArticleRepo $repo,
        PublishService $publish,
    ) {
        $this->onContentPreSave = function (ContentPreSaveContext $ctx) use ($repo): PreSaveDecision {
            $email = trim((string) ($ctx->metadata['user_email'] ?? ''));
            if ($email === '' || !Validation::isValidEmail($email)) {
                return new PreSaveDecision(allow: false, reason: 'user_email required');
            }
            if ($repo->getCmsUser($email) === null) {
                return new PreSaveDecision(allow: false, reason: 'user not in cms_users');
            }
            if ($repo->articleByMaterialId($ctx->materialId) === null) {
                return new PreSaveDecision(allow: false, reason: 'unknown material');
            }

            return new PreSaveDecision(allow: true);
        };

        $this->onFontsPreSave = function (FontsPreSaveContext $ctx) use ($repo): PreSaveDecision {
            $email = trim((string) ($ctx->metadata['user_email'] ?? ''));
            if ($email === '') {
                return new PreSaveDecision(allow: true);
            }
            if (!Validation::isValidEmail($email)) {
                return new PreSaveDecision(allow: false, reason: 'invalid user_email');
            }
            if ($repo->getCmsUser($email) === null) {
                return new PreSaveDecision(allow: false, reason: 'user not in cms_users');
            }

            return new PreSaveDecision(allow: true);
        };

        $this->onContentFinalize = function (ContentFinalizeContext $ctx) use ($repo, $publish): ContentFinalizeResult {
            $html = $ctx->vmsHtml ?? '';
            $vmsJsonStr = $ctx->vmsJson !== null
                ? json_encode($ctx->vmsJson, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                : null;
            $repo->updateArticleFromVerstka($ctx->materialId, $html, $vmsJsonStr);
            $row = $repo->articleByMaterialId($ctx->materialId);
            $publish->publishArticleChange($row, removeIfHidden: true);

            return new ContentFinalizeResult(success: true, vmsJson: $ctx->vmsJson);
        };

        $this->onFontsFinalize = function (FontsFinalizeContext $ctx) use ($settings, $publish): FontsFinalizeResult {
            unset($ctx);
            $src = $settings->storageDir . '/fonts/vms_fonts.css';
            $dst = $settings->storageDir . '/fonts/fonts.css';
            if (is_file($src)) {
                copy($src, $dst);
            }
            $publish->regenerateAllVisibleIndexes();
            $publish->writeSitemap();

            return new FontsFinalizeResult(success: true);
        };
    }
}
