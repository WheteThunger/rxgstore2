{% extends 'Common/base.tpl' %}

{% set title = 'Admin Control Panel' %}

{% block content %}

    <h1 class="page_heading">Admin Control Panel</h1>

    <p>This is the admin control panel where you can do special things.</p>

    <p>{{ html.link('Click here', {'controller': 'Admin', 'action': 'viewlog', 'name': 'admin'}) }} to view the admin log.</p>

    <ul class="business_list">
        {% if access.check('Rewards') %}
            <li>{{ html.link('Send a Reward', {'controller': 'Rewards', 'action': 'compose'}) }}</li>
        {% endif %}
        {% if access.check('Stock', 'update') %}
            <li>{{ html.link('Receive a Shipment', {'controller': 'Stock', 'action': 'edit'}) }}</li>
        {% endif %}
        {% if access.check('Items', 'update') %}
            <li>{{ html.link('Change Item Display Order', {'controller': 'Items', 'action': 'sort'}) }}</li>
        {% endif %}
        {% if access.check('Permissions', 'read') %}
            <li>{{ html.link('View Permissions', {'controller': 'Permissions', 'action': 'view'}) }}</li>
        {% endif %}
        {% if access.check('Cache', 'read') %}
            <li>{{ html.link('View Steam Cache', {'controller': 'SteamPlayerCache', 'action': 'view'}) }}</li>
        {% endif %}
        {% if access.check('QuickAuth') %}
            <li>{{ html.link('View QuickAuth Records', {'controller': 'QuickAuth', 'action': 'view'}) }}</li>
        {% endif %}
    </ul>

{% endblock %}