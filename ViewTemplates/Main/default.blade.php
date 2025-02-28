<?php
/**
 * @package   panopticon
 * @copyright Copyright (c)2023-2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

/** @var \Akeeba\Panopticon\View\Main\Html $this */

defined('AKEEBA') || die;

/**
 * @var \Akeeba\Panopticon\View\Main\Html $this
 * @var \Akeeba\Panopticon\Model\Site $model
 * @var \Akeeba\Panopticon\Model\Main $mainModel
 */
$model      = $this->getModel();
$mainModel  = $this->getModel('main');
$user       = $this->container->userManager->getUser();
$canCreate  = $user->getPrivilege('panopticon.admin') || $user->getPrivilege('panopticon.addown');
$isFiltered = array_reduce(
	['search', 'coreUpdates', 'extUpdates', 'phpFamily', 'cmsFamily'],
	fn(bool $carry, string $filterKey) => $carry || $model->getState($filterKey) !== null,
	false
);

$js = <<< JS
window.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.js-choice').forEach((element) => {
        new Choices(element, {allowHTML: false, removeItemButton: true, placeholder: true, placeholderValue: ""});
    });
});

JS;

?>

@if($this->container->userManager->getUser()->getPrivilege('panopticon.super'))
    @include('Main/heartbeat')
    @include('Main/cronfellbehind')
    @include('Main/php_warnings')
    @include('Main/selfupdate')
@endif

@js('choices/choices.min.js', $this->getContainer()->application)
@inlinejs($js)

{{-- My Sites --}}
@if ($this->itemsCount || $isFiltered)
<form name="sitesForm" id="adminForm" method="post"
      action="@route('index.php?view=main')">

    {{-- Filters --}}
    <div class="mt-2 mb-3 border rounded-1 p-2 bg-body-tertiary align-items-center">
        <div class="d-flex flex-column flex-lg-row gap-2 gap-lg-3 justify-content-center align-items-center">
            {{-- Search --}}
            <div class="input-group" @if(empty($this->groupMap)) style="max-width: max(25em, 50%)" @endif>
                <input type="search" class="form-control form-control-lg" id="search"
                       placeholder="@lang('PANOPTICON_LBL_FORM_SEARCH')"
                       name="search" value="{{{ $model->getState('search', '') }}}">
                <label for="search" class="sr-only">@lang('PANOPTICON_LBL_FORM_SEARCH')</label>
                <button type="submit"
                        class="btn btn-primary">
                    <span class="fa fa-search" aria-hidden="true"></span>
                    <span class="visually-hidden">
                        @lang('PANOPTICON_LBL_FORM_SEARCH')
                    </span>
                </button>
            </div>
            {{-- Groups --}}
            @if (!empty($this->groupMap))
            <div class="input-group choice-large">
                <label for="group" class="form-label visually-hidden">@lang('PANOPTICON_MAIN_LBL_FILTER_GROUPS')</label>
                {{ $this->container->html->select->genericList(
                    data: array_combine(
						array_merge([''], array_keys($this->groupMap)),
						array_merge([$this->getLanguage()->text('PANOPTICON_MAIN_LBL_FILTER_GROUPS_PLACEHOLDER')], array_values($this->groupMap)),
                    ),
                    name: 'group[]',
                    attribs: array_merge([
                        'class' => 'form-select js-choice',
                        'multiple' => 'multiple',
                        'style' => 'min-width: min(20em, 50%)'
                    ]),
                    selected: array_filter($this->getModel()->getState('group', []) ?: [])
                ) }}
                <button type="submit"
                        class="btn btn-primary">
                    <span class="fa fa-search" aria-hidden="true"></span>
                    <span class="visually-hidden">
                        @lang('PANOPTICON_LBL_FORM_SEARCH')
                    </span>
                </button>
            </div>
            @endif
        </div>
        {{-- Drop-down filters --}}
        <div class="d-flex flex-column flex-lg-row justify-content-lg-center gap-3 mt-2">
            {{-- coreUpdates Has Core Updates --}}
            <div>
                <label for="coreUpdates" class="form-label">@lang('PANOPTICON_MAIN_LBL_FILTER_COREUPDATES')</label>
                {{ $this->container->html->select->genericList( [
                    '' => 'PANOPTICON_MAIN_LBL_FILTER_DROPDOWN_SELECT',
                    '0' => 'AWF_NO',
                    '1' => 'AWF_YES',
                ], 'coreUpdates', [
                    'class' => 'form-select akeebaGridViewAutoSubmitOnChange',
                ], selected: $model->getState('coreUpdates'),
                idTag: 'coreUpdates',
                translate: true) }}
            </div>
            {{-- extUpdates Has extension updates --}}
            <div>
                <label for="extUpdates" class="form-label">@lang('PANOPTICON_MAIN_LBL_FILTER_EXTUPDATES')</label>
                {{ $this->container->html->select->genericList( [
                    '' => 'PANOPTICON_MAIN_LBL_FILTER_DROPDOWN_SELECT',
                    '0' => 'AWF_NO',
                    '1' => 'AWF_YES',
                ], 'extUpdates', [
                    'class' => 'form-select akeebaGridViewAutoSubmitOnChange',
                ], selected: $model->getState('extUpdates'),
                idTag: 'extUpdates',
                translate: true) }}
            </div>
            {{-- cmsFamily CMS Version --}}
            <div>
                <label class="form-label" for="cmsFamily">@lang('PANOPTICON_MAIN_LBL_FILTER_CMSFAMILY')</label>
                {{ $this->container->html->select->genericList(
                    array_merge([
                        '' => $this->getLanguage()->text('PANOPTICON_MAIN_LBL_FILTER_DROPDOWN_SELECT')
                    ], $mainModel->getKnownJoomlaVersions()),
                    'cmsFamily',
                    [
                        'class' => 'form-select akeebaGridViewAutoSubmitOnChange',
                    ],
                selected: $model->getState('cmsFamily'),
                idTag: 'cmsFamily',
                translate: false) }}
            </div>
            {{-- phpFamily PHP Version --}}
            <div>
                <label class="form-label" for="phpFamily">@lang('PANOPTICON_MAIN_LBL_FILTER_PHPFAMILY')</label>
                {{ $this->container->html->select->genericList(
                    array_merge([
                        '' => $this->getLanguage()->text('PANOPTICON_MAIN_LBL_FILTER_DROPDOWN_SELECT')
                    ], $mainModel->getKnownPHPVersions()),
                    'phpFamily',
                    [
                        'class' => 'form-select akeebaGridViewAutoSubmitOnChange',
                    ],
                selected: $model->getState('phpFamily'),
                idTag: 'phpFamily',
                translate: false) }}
            </div>

        </div>
    </div>

    {{-- Results table --}}
    <table class="table table-striped table-hover table-sm align-middle table-responsive-sm">
        <caption class="visually-hidden">
            @lang('PANOPTICON_MAIN_SITES_TABLE_CAPTION')
        </caption>
        <thead class="table-secondary">
        <tr>
            <th>
                {{ $this->getContainer()->html->grid->sort('PANOPTICON_MAIN_SITES_THEAD_SITE', 'name', $this->lists->order_Dir, $this->lists->order, 'browse') }}
            </th>
            <th>
                <span class="fab fa-joomla fs-3" aria-hidden="true"
                      data-bs-toggle="tooltip" data-bs-placement="bottom"
                      data-bs-title="@lang('PANOPTICON_MAIN_SITES_THEAD_JOOMLA')"
                ></span>
                <span class="visually-hidden">
                @lang('PANOPTICON_MAIN_SITES_THEAD_JOOMLA')
                </span>
            </th>
            <th>
                <span class="fa fa-cubes fs-3" aria-hidden="true"
                      data-bs-toggle="tooltip" data-bs-placement="bottom"
                      data-bs-title="@lang(  'PANOPTICON_MAIN_SITES_THEAD_EXTENSIONS')"
                ></span>
                <span class="visually-hidden">
                @lang('PANOPTICON_MAIN_SITES_THEAD_EXTENSIONS')
                </span>
            </th>
            <th>
                <span class="fab fa-php fs-3" aria-hidden="true"
                      data-bs-toggle="tooltip" data-bs-placement="bottom"
                      data-bs-title="@lang('PANOPTICON_MAIN_SITES_THEAD_PHP')"
                ></span>
                <span class="visually-hidden">
                @lang('PANOPTICON_MAIN_SITES_THEAD_PHP')
                </span>
            </th>
            <th style="min-width: 2em">
                {{ $this->getContainer()->html->grid->sort('PANOPTICON_LBL_TABLE_HEAD_NUM', 'id', $this->lists->order_Dir, $this->lists->order, 'browse') }}
            </th>
        </tr>
        </thead>
        <tbody class="table-group-divider">
        <?php
        /** @var \Akeeba\Panopticon\Model\Site $item */
        ?>
        @foreach($this->items as $item)
            <?php
            $url    = $item->getBaseUrl();
            $config = new Awf\Registry\Registry($item->config);
            ?>
            <tr>
                <td>
                    <a class="fw-medium"
                       href="@route(sprintf('index.php?view=site&task=read&id=%s', $item->id))">
                        {{{ $item->name }}}
                    </a>
                    <div class="small mt-1">
                        <span class="visually-hidden">@lang('PANOPTICON_MAIN_SITES_LBL_URL_SCREENREADER')</span>
                        <a href="{{{ $url }}}" class="link-secondary text-decoration-none" target="_blank">
                            {{{ $url }}}
                            <span class="fa fa-external-link-square" aria-hidden="true"></span>
                        </a>
                    </div>
                    {{-- Show group labels --}}
                    @if (!empty($groups = $config->get('config.groups')))
                    <div>
                        @foreach($groups as $gid)
                            @if (isset($this->groupMap[$gid]))
                                <span class="badge bg-secondary">
                                    {{{ $this->groupMap[$gid] }}}
                                </span>
                            @endif
                        @endforeach
                    </div>
                    @endif
                </td>
                <td>
                    @include('Main/site_joomla', [
                        'item' => $item,
                        'config' => $config,
                    ])
                </td>
                <td>
                    @include('Main/site_extensions', [
                        'item' => $item,
                        'config' => $config,
                    ])
                </td>
                <td>
                    @include('Main/site_php', [
                        'item' => $item,
                        'config' => $config,
                        'php' => $config->get('core.php')
                    ])
                </td>
                <td class="font-monospace text-body-tertiary small px-2">
                    {{{ $item->id }}}
                </td>
            </tr>
        @endforeach
        @if ($this->itemsCount == 0)
            <tr>
                <td colspan="20">
                    <div class="alert alert-info m-2">
                        <span class="fa fa-info-circle" aria-hidden="true"></span>
                        @lang('PANOPTICON_MAIN_SITES_LBL_NO_RESULTS')
                    </div>
                </td>
            </tr>
        @endif
        </tbody>
    </table>
    {{ $this->pagination->getListFooter(['class' => 'form-select akeebaGridViewAutoSubmitOnChange']) }}
    <input type="hidden" name="task" id="task" value="browse">
    <input type="hidden" name="filter_order" id="filter_order" value="{{{ $this->lists->order }}}">
    <input type="hidden" name="filter_order_Dir" id="filter_order_Dir" value="{{{ $this->lists->order_Dir }}}">
</form>
@else
    <div class="d-flex flex-column align-items-center gap-3 mt-4">
        <div class="text-body-tertiary">
            <span class="fa fa-globe display-1" aria-hidden="true"></span>
        </div>
        <div class="display-1">
            @lang('PANOPTICON_MAIN_SITES_LBL_NOSITES_HEAD')
        </div>
        <div class="display-6 text-center text-secondary" style="max-width: 600px">
            @lang('PANOPTICON_MAIN_SITES_LBL_NOSITES_CTA')
        </div>
        <div class="py-5 mb-2">
            <a href="@route('index.php?view=sites&task=add')"
               class="btn btn-primary btn-lg" role="button">
                <span class="fa fa-plus" aria-hidden="true"></span>
                @lang('PANOPTICON_MAIN_SITES_LBL_NOSITES_BUTTON')
            </a>
        </div>
    </div>
@endif
