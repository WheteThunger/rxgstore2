{% extends 'Common/base.tpl' %}

{% set title = "Editing: #{item.name}" %}
{% set scripts = 'item-edit' %}


{% macro label(_, name, label) %}
    {% if label != '' %}
        {{ _.form.label(name, label, {'class': 'edit_label'}) }}
    {% endif %}
{% endmacro %}

{% macro input(_, name, value, options, extra) %}
    {{ _.form.input(name, {'value': value, 'class': 'edit_input'}|merge(options ?: [])) }}
    {{ extra }}
{% endmacro %}

{% macro field(_, name, label, value, options, extra) %}
    <div class="edit_field">
        {{ _self.label(_, name, label) }}
        {{ _self.input(_, name, value, options, extra) }}
    </div>
{% endmacro %}

{% macro fields(_, fields) %}
    {% for field in fields %}
        {{ _self.field(_, field[0], field[1], field[2], field[3], field[4]) }}
    {% endfor %}
{% endmacro %}


{% block content %}

    {{ html.image("items/#{item.short_name}.png", {
        'url': {'action': 'view', 'id': item.short_name},
        'class': 'item_edit_image'
    }) }}

    <div class="back_link">
        <i class="fa fa-arrow-circle-left"></i>
        {{ html.link('Return to Item',
            {'action': 'view', 'id': item.short_name}
        ) }}
    </div>

    {{ form.create('Item', {
        'inputDefaults': {
            'label': false,
            'div': false
        }
    }) }}

    {{ _self.fields(_context, [
        ['item_id', 'Item ID', item.item_id, {}, item.item_id],
        ['buyable', 'Buyable', 1, {'checked': item.buyable}],
        ['price', 'Price (cents x' ~ currencyMult ~ ')', item.price],
        ['name', 'Full Name', item.name],
        ['plural', 'Full Plural Name', item.plural],
        ['short_name', 'In-game Usage', item.short_name],
        ['Stock.item_id', '', stock.item_id,],
        ['Stock.ideal_quantity', 'Ideal Stock', stock.ideal_quantity, {}, '~' ~ suggested],
        ['Stock.minimum', 'Minimum Stock', stock.minimum],
        ['Stock.maximum', 'Maximum Stock', stock.maximum]
    ]) }}

    <div class="edit_multiple">
        {{ _self.label(_context, '', 'Servers') }}
        <input type="hidden" id="childServers" value="{{ childServers }}" />
        <div class="edit_input">
            {{ form.input('ServerItem.server_id', {
                'type': 'select',
                'multiple': 'checkbox',
                'options': servers,
                'selected': selectedServers,
                'class': 'item_edit_servers'
            }) }}
        </div>
    </div>

    <div class="edit_multiple">
        {{ _self.label(_context, '', 'Features') }}
        <div class="item_edit_features">
        {% if features %}

            {% for feature in features %}
                <div class="item_edit_feature">
                    {{ form.hidden('Feature.' ~ loop.index0 ~ '.feature_id', {'value': feature.feature_id, 'class': 'item_edit_feature_id'}) }}
                    {{ form.input('Feature.' ~ loop.index0 ~ '.description', {
                        'class': 'edit_input',
                        'value': feature.description,
                        'required': false
                    }) }}
                    <i class="fa fa-trash feature_remove" title="remove"></i>
                </div>
            {% endfor %}

        {% else %}

            <div class="item_edit_feature">
                {{ form.input('Feature.0.description', {
                    'class': 'edit_input',
                    'value': feature.description,
                    'required': false
                }) }}
                {{ html.image('misc/remove.png', {
                    'class': 'feature_remove',
                    'title': 'remove',
                    'height': 20,
                    'width': 20
                }) }}
            </div>

        {% endif %}
            <a id="item_edit_feature_add">+ Add New Feature</a>
        </div>
    </div>

    <div class="item_edit_description_label">
        Description (parsed as <a href="http://daringfireball.net/projects/markdown/basics">markdown</a>)
    </div>

    {{ form.input('description', {
        'label': false,
        'type': 'textarea',
        'value': item.description,
        'id': 'item_edit_description'
    }) }}

    {{ html.image(
        'misc/ajax-loader.gif',
        {'class': 'ajax-loader item_preview_loading'}
    ) }}

    {{ form.submit('Save Item', {
        'class': 'btn-primary',
        'div': false
    }) }}

    <input type="button" id="item_preview" class="btn-secondary" value="Preview Description" data-href="{{ html.url({'action': 'preview'}) }}">

    {{ form.end() }}

    <div class="item_preview_divider"></div>
    <div id="item_preview_content" class="item_description"></div>

{% endblock %}