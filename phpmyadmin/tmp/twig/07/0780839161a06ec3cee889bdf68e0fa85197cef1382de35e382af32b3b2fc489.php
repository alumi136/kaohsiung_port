<?php

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Extension\SandboxExtension;
use Twig\Markup;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Source;
use Twig\Template;

/* database/events/row.twig */
class __TwigTemplate_9b5606dc0331eff48cf2dcfdb7c2421aff44a549f876fc4a9dbaeffaabdb3f75 extends \Twig\Template
{
    private $source;
    private $macros = [];

    public function __construct(Environment $env)
    {
        parent::__construct($env);

        $this->source = $this->getSourceContext();

        $this->parent = false;

        $this->blocks = [
        ];
    }

    protected function doDisplay(array $context, array $blocks = [])
    {
        $macros = $this->macros;
        // line 1
        echo "<tr";
        if ( !twig_test_empty(($context["row_class"] ?? null))) {
            echo " class=\"";
            echo twig_escape_filter($this->env, ($context["row_class"] ?? null), "html", null, true);
            echo "\"";
        }
        echo ">
  <td>
    <input type=\"checkbox\" class=\"checkall\" name=\"item_name[]\" value=\"";
        // line 3
        echo twig_escape_filter($this->env, twig_get_attribute($this->env, $this->source, ($context["event"] ?? null), "name", [], "any", false, false, false, 3), "html", null, true);
        echo "\">
  </td>
  <td>
    <span class='drop_sql hide'>";
        // line 6
        echo twig_escape_filter($this->env, ($context["sql_drop"] ?? null), "html", null, true);
        echo "</span>
    <strong>";
        // line 7
        echo twig_escape_filter($this->env, twig_get_attribute($this->env, $this->source, ($context["event"] ?? null), "name", [], "any", false, false, false, 7), "html", null, true);
        echo "</strong>
  </td>
  <td>
    ";
        // line 10
        echo twig_escape_filter($this->env, twig_get_attribute($this->env, $this->source, ($context["event"] ?? null), "status", [], "any", false, false, false, 10), "html", null, true);
        echo "
  </td>
  <td>
    ";
        // line 13
        if (($context["has_privilege"] ?? null)) {
            // line 14
            echo "      <a class=\"ajax edit_anchor\" href=\"";
            echo PhpMyAdmin\Url::getFromRoute("/database/events", ["db" =>             // line 15
($context["db"] ?? null), "table" =>             // line 16
($context["table"] ?? null), "edit_item" => true, "item_name" => twig_get_attribute($this->env, $this->source,             // line 18
($context["event"] ?? null), "name", [], "any", false, false, false, 18)]);
            // line 19
            echo "\">
        ";
            // line 20
            echo \PhpMyAdmin\Html\Generator::getIcon("b_edit", _gettext("Edit"));
            echo "
      </a>
    ";
        } else {
            // line 23
            echo "      ";
            echo \PhpMyAdmin\Html\Generator::getIcon("bd_edit", _gettext("Edit"));
            echo "
    ";
        }
        // line 25
        echo "  </td>
  <td>
    <a class=\"ajax export_anchor\" href=\"";
        // line 27
        echo PhpMyAdmin\Url::getFromRoute("/database/events", ["db" =>         // line 28
($context["db"] ?? null), "table" =>         // line 29
($context["table"] ?? null), "export_item" => true, "item_name" => twig_get_attribute($this->env, $this->source,         // line 31
($context["event"] ?? null), "name", [], "any", false, false, false, 31)]);
        // line 32
        echo "\">
      ";
        // line 33
        echo \PhpMyAdmin\Html\Generator::getIcon("b_export", _gettext("Export"));
        echo "
    </a>
  </td>
  <td>
    ";
        // line 37
        if (($context["has_privilege"] ?? null)) {
            // line 38
            echo "      ";
            echo PhpMyAdmin\Html\Generator::linkOrButton(PhpMyAdmin\Url::getFromRoute("/sql", ["db" =>             // line 40
($context["db"] ?? null), "table" =>             // line 41
($context["table"] ?? null), "sql_query" =>             // line 42
($context["sql_drop"] ?? null), "goto" => PhpMyAdmin\Url::getFromRoute("/database/events", ["db" =>             // line 43
($context["db"] ?? null)])]), \PhpMyAdmin\Html\Generator::getIcon("b_drop", _gettext("Drop")), ["class" => "ajax drop_anchor"]);
            // line 47
            echo "
    ";
        } else {
            // line 49
            echo "      ";
            echo \PhpMyAdmin\Html\Generator::getIcon("bd_drop", _gettext("Drop"));
            echo "
    ";
        }
        // line 51
        echo "  </td>
  <td>
    ";
        // line 53
        echo twig_escape_filter($this->env, twig_get_attribute($this->env, $this->source, ($context["event"] ?? null), "type", [], "any", false, false, false, 53), "html", null, true);
        echo "
  </td>
</tr>
";
    }

    public function getTemplateName()
    {
        return "database/events/row.twig";
    }

    public function isTraitable()
    {
        return false;
    }

    public function getDebugInfo()
    {
        return array (  134 => 53,  130 => 51,  124 => 49,  120 => 47,  118 => 43,  117 => 42,  116 => 41,  115 => 40,  113 => 38,  111 => 37,  104 => 33,  101 => 32,  99 => 31,  98 => 29,  97 => 28,  96 => 27,  92 => 25,  86 => 23,  80 => 20,  77 => 19,  75 => 18,  74 => 16,  73 => 15,  71 => 14,  69 => 13,  63 => 10,  57 => 7,  53 => 6,  47 => 3,  37 => 1,);
    }

    public function getSourceContext()
    {
        return new Source("", "database/events/row.twig", "/var/www/html/phpmyadmin/templates/database/events/row.twig");
    }
}
