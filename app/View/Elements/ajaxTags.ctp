<?php
    if (empty($scope)) {
        $scope = 'event';
    }
    $searchUrl = '/events/index/searchtag:';
    switch ($scope) {
        case 'event':
            $id = intval($event['Event']['id']);
            if (!empty($missingTaxonomies)) {
                echo __(
                    'Missing taxonomies: <span class="red bold">%s</span><br>',
                    implode(', ', $missingTaxonomies)
                );
            }
            break;
        case 'attribute':
            $id = $attributeId;
            $searchUrl = '/attributes/search/tags:';
            if (!empty($server)) {
                $searchUrl = sprintf("/servers/previewIndex/%s/searchtag:", h($server['Server']['id']));
            }
            break;
    }
    $full = $isAclTagger && $tagAccess && empty($static_tags_only);
    $fullLocal = $isAclTagger && $localTagAccess && empty($static_tags_only);
    $tagData = "";
    foreach ($tags as $tag) {
        if (empty($tag['Tag'])) {
            $tag['Tag'] = $tag;
        }
        if (empty($tag['Tag']['colour'])) {
            $tag['Tag']['colour'] = '#0088cc';
        }
        $aStyle = 'background-color:' . h($tag['Tag']['colour']) . ';color:' . $this->TextColour->getTextColour($tag['Tag']['colour']);
        $aClass = 'tag nowrap';
        $aText = trim($tag['Tag']['name']);
        $aTextModified = null;
        if (isset($tag_display_style)) {
            if ($tag_display_style == 1) {
                // default behaviour, do nothing for now
            } else if ($tag_display_style == 2) {
                $separator_pos = strpos($aText, ':');
                if ($separator_pos !== false) {
                    $aTextModified = substr($aText, $separator_pos + 1);
                    $value_pos = strpos($aTextModified, '=');
                    if ($value_pos !== false) {
                        $aTextModified = substr($aTextModified, $value_pos + 1);
                        $aTextModified = trim($aTextModified, '"');
                    }
                    $aTextModified = h($aTextModified);
                }
            } else if ($tag_display_style === 0 || $tag_display_style === '0') {
                $aTextModified = '&nbsp;';
            }
        }
        $aText = h($aText);
        $span_scope = !empty($hide_global_scope) ? '' : sprintf(
            '<span class="%s" title="%s" aria-label="%s"><i class="fas fa-%s"></i></span>',
            'black-white tag',
            !empty($tag['local']) ? __('Local tag') : __('Global tag'),
            !empty($tag['local']) ? __('Local tag') : __('Global tag'),
            !empty($tag['local']) ? 'user' : 'globe-americas'
        );
        if (!empty($tag['Tag']['id'])) {
            $span_tag = sprintf(
                '<a href="%s" style="%s" class="%s"%s data-tag-id="%s">%s</a>',
                $baseurl . $searchUrl . intval($tag['Tag']['id']),
                $aStyle,
                $aClass,
                isset($aTextModified) ? ' title="' . $aText . '"' : '',
                intval($tag['Tag']['id']),
                isset($aTextModified) ? $aTextModified : $aText
            );
        } else {
            $span_tag = sprintf(
                '<span style="%s" class="%s">%s</span>',
                $aStyle,
                $aClass,
                $aText
            );
        }
        $span_delete = '';
        if ($full || ($fullLocal && $tag['Tag']['local'])) {
            $span_delete = sprintf(
                '<span class="%s" title="%s" role="%s" tabindex="%s" aria-label="%s" onclick="%s">x</span>',
                'black-white tag useCursorPointer noPrint',
                __('Remove tag'),
                "button",
                "0",
                __('Remove tag %s', h($tag['Tag']['name'])),
                sprintf(
                    "removeObjectTagPopup(this, '%s', %s, %s)",
                     $scope,
                     $id,
                     intval($tag['Tag']['id'])
                )
            );
        }
        $tagData .= '<span class="tag-container nowrap">' . $span_scope . $span_tag . $span_delete . '</span> ';
    }
    $buttonData = array();
    if ($full) {
        $buttonData[] = sprintf(
            '<button title="%s" role="button" tabindex="0" aria-label="%s" class="%s" data-popover-popup="%s">%s</button>',
            __('Add a tag'),
            __('Add a tag'),
            'addTagButton addButton btn btn-inverse noPrint',
            $baseurl . '/tags/selectTaxonomy/' . $id . ($scope === 'event' ? '' : ('/' . $scope)),
            '<i class="fas fa-globe-americas"></i> <i class="fas fa-plus"></i>'
        );
    }
    if ($full || $fullLocal) {
        $buttonData[] = sprintf(
            '<button title="%s" role="button" tabindex="0" aria-label="%s" class="%s" data-popover-popup="%s">%s</button>',
            __('Add a local tag'),
            __('Add a local tag'),
            'addLocalTagButton addButton btn btn-inverse noPrint',
            $baseurl . '/tags/selectTaxonomy/local:1/' . $id . ($scope === 'event' ? '' : ('/' . $scope)),
            '<i class="fas fa-user"></i> <i class="fas fa-plus"></i>'
        );
    }
    if (!empty($buttonData)) {
        $tagData .= '<span style="white-space:nowrap">' . implode('', $buttonData) . '</span>';
    }
    echo sprintf(
        '<span class="tag-list-container">%s</span>',
        $tagData
    );
    if (!empty($tagConflicts['global'])) {
        echo '<div><div class="alert alert-error tag-conflict-notice">';
        echo '<i class="fas fa-globe-americas icon"></i>';
        echo '<div class="text-container">';
        foreach ($tagConflicts['global'] as $tagConflict) {
            echo sprintf(
                '<strong>%s</strong><br>',
                h($tagConflict['conflict'])
            );
            foreach ($tagConflict['tags'] as $tag) {
                echo sprintf('<span class="apply_css_arrow nowrap">%s</span><br>', h($tag));
            }
        }
        echo '</div></div></span>';
    }
    if (!empty($tagConflicts['local'])) {
        echo '<div><div class="alert alert-error tag-conflict-notice">';
        echo '<i class="fas fa-user icon"></i>';
        echo '<div class="text-container">';
        foreach ($tagConflicts['local'] as $tagConflict) {
            echo sprintf(
                '<strong>%s</strong><br>',
                h($tagConflict['conflict'])
            );
            foreach ($tagConflict['tags'] as $tag) {
                echo sprintf('<span class="apply_css_arrow nowrap">%s</span><br>', h($tag));
            }
        }
        echo '</div></div></span>';
    }
