<?php

namespace Codeception\PHPUnit\ResultPrinter;

use Codeception\PHPUnit\ResultPrinter as CodeceptionResultPrinter;
use Codeception\Step;
use Codeception\Step\Meta;
use Exception;
use InvalidArgumentException;
use PHPUnit_Framework_AssertionFailedError;
use PHPUnit_Framework_Test;

class JSON extends CodeceptionResultPrinter
{
    /**
     * @var boolean
     */
    protected $printsHTML = true;

    /**
     * @var integer
     */
    protected $id = 0;

    /**
     * @var string
     */
    protected $scenarios = '';

    /**
     * @var string
     */
    protected $suite = '';

    /**
     * @var string
     */
    protected $templatePath;

    /**
     * @var int
     */
    protected $timeTaken = 0;

    protected $failures = [];

    private $openDelimiter = '%%--';

    private $closeDelimiter = '--%%';

    /**
     * Constructor.
     *
     * @param  mixed $out
     *
     * @throws InvalidArgumentException
     */
    public function __construct($out = null)
    {
        parent::__construct($out);

        $this->templatePath = sprintf(
            '%s%stemplate%s',
            dirname(__FILE__),
            DIRECTORY_SEPARATOR,
            DIRECTORY_SEPARATOR
        );
    }

    /**
     * Handler for 'on test' event.
     *
     * @param  string $name
     * @param  boolean $success
     * @param  array $steps
     * @param  int $time
     *
     * @
     */
    protected function onTest($name, $success = true, array $steps = [], $time = 0)
    {
        parent::onTest($name, $success);

        $this->timeTaken += $time;

        switch ($this->testStatus) {
            case \PHPUnit_Runner_BaseTestRunner::STATUS_FAILURE:
                $scenarioStatus = 'scenarioFailed';
                break;
            case \PHPUnit_Runner_BaseTestRunner::STATUS_SKIPPED:
                $scenarioStatus = 'scenarioSkipped';
                break;
            case \PHPUnit_Runner_BaseTestRunner::STATUS_INCOMPLETE:
                $scenarioStatus = 'scenarioIncomplete';
                break;
            case \PHPUnit_Runner_BaseTestRunner::STATUS_ERROR:
                $scenarioStatus = 'scenarioFailed';
                break;
            default:
                $scenarioStatus = 'scenarioSuccess';
        }

        $stepsBuffer = $this->renderStepsInBuffer($steps);

        $scenarioTemplate = new \Text_Template(
            $this->templatePath . 'scenario.json',
            $this->openDelimiter,
            $this->closeDelimiter
        );

        $failure = '""';
        if (isset($this->failures[$name])) {
            $failTemplate = new \Text_Template(
                $this->templatePath . 'fail.json',
                $this->openDelimiter,
                $this->closeDelimiter
            );
            $failTemplate->setVar(['fail' => json_encode($this->failures[$name])]);
            $failure = $failTemplate->render();
        }

        $stepsBuffer = trim(trim($stepsBuffer), ',');

        $scenarioTemplate->setVar(
            [
                'id' => ++$this->id,
                'name' => json_encode(ucfirst($name)),
                'scenarioStatus' => json_encode($scenarioStatus),
                'steps' => $stepsBuffer,
                'failure' => $failure,
                'time' => round($time, 2)
            ]
        );

        $this->scenarios .= $scenarioTemplate->render();
    }

    /**
     * @param array $steps
     *
     * @return string
     */
    public function renderStepsInBuffer(array $steps)
    {
        $stepsBuffer = $subStepsBuffer = '';
        $metaStep = null;

        foreach ($steps as $step) {
            /** @var $step Step  * */
            if ($step->getMetaStep()) {
                $subStepsBuffer .= $this->renderStep($step);
                $metaStep = $step->getMetaStep();
                continue;
            }
            if ($step->getMetaStep() != $metaStep) {
                $stepsBuffer .= $this->renderSubsteps($metaStep, $subStepsBuffer);
                $subStepsBuffer = '';
            }
            $metaStep = $step->getMetaStep();
            $stepsBuffer .= $this->renderStep($step);
        }

        if ($subStepsBuffer and $metaStep) {
            $stepsBuffer .= trim(trim($this->renderSubsteps($metaStep, $subStepsBuffer)), ',');
        }

        return $stepsBuffer;
    }

    /**
     * @param \PHPUnit_Framework_TestSuite $suite
     */
    public function endTestSuite(\PHPUnit_Framework_TestSuite $suite)
    {
        $suiteTemplate = new \Text_Template(
            $this->templatePath . 'suite.json',
            $this->openDelimiter,
            $this->closeDelimiter
        );

        $suiteTemplate->setVar([
            'suite' => json_encode(ucfirst($suite->getName())),
            'scenarios' => trim(trim($this->scenarios), ',')
        ]);

        $this->suite .= $suiteTemplate->render();

        // reset scenarios buffer
        $this->scenarios = '';
    }

    /**
     * Handler for 'end run' event.
     *
     */
    protected function endRun()
    {
        $scenarioHeaderTemplate = new \Text_Template(
            $this->templatePath . 'scenario_header.json',
            $this->openDelimiter,
            $this->closeDelimiter
        );

        $status = !$this->failed ? 'OK' : 'FAILED';

        $scenarioHeaderTemplate->setVar(
            [
                'name' => 'Codeception Results',
                'status' => json_encode($status),
                'time' => round($this->timeTaken, 1)
            ]
        );

        $header = $scenarioHeaderTemplate->render();

        $scenariosTemplate = new \Text_Template(
            $this->templatePath . 'scenarios.json',
            $this->openDelimiter,
            $this->closeDelimiter
        );

        $scenariosTemplate->setVar(
            [
                'header' => $header,
                'scenarios' => trim(trim($this->scenarios), ','),
                'suite' => trim(trim($this->suite), ','),
                'successfulScenarios' => $this->successful,
                'failedScenarios' => $this->failed,
                'skippedScenarios' => $this->skipped,
                'incompleteScenarios' => $this->incomplete
            ]
        );

        $this->write($scenariosTemplate->render());
    }

    /**
     * An error occurred.
     *
     * @param PHPUnit_Framework_Test $test
     * @param Exception $e
     * @param float $time
     */
    public function addError(PHPUnit_Framework_Test $test, Exception $e, $time)
    {
        $this->failures[$test->toString()] = $e->getMessage();
        parent::addError($test, $e, $time);
    }

    /**
     * A failure occurred.
     *
     * @param PHPUnit_Framework_Test $test
     * @param PHPUnit_Framework_AssertionFailedError $e
     * @param float $time
     */
    public function addFailure(PHPUnit_Framework_Test $test, PHPUnit_Framework_AssertionFailedError $e, $time)
    {
        $this->failures[$test->toString()] = $e->getMessage();
        parent::addFailure($test, $e, $time);
    }

    /**
     * @param $step
     *
     * @return string
     */
    protected function renderStep(Step $step)
    {
        $stepTemplate = new \Text_Template(
            $this->templatePath . 'step.json',
            $this->openDelimiter,
            $this->closeDelimiter
        );
        $stepTemplate->setVar([
            'action' => json_encode(strip_tags($step->getHtml())),
            'error' => $step->hasFailed() ?
                '"failedStep"' : '""'
        ]);

        return $stepTemplate->render();
    }

    /**
     * @param $metaStep
     * @param $substepsBuffer
     *
     * @return string
     */
    protected function renderSubsteps(Meta $metaStep, $substepsBuffer)
    {
        $metaTemplate = new \Text_Template(
            $this->templatePath . 'substeps.json',
            $this->openDelimiter,
            $this->closeDelimiter
        );

        $metaTemplate->setVar(['metaStep' => json_encode($metaStep), 'steps' => $substepsBuffer, 'id' => uniqid()]);

        return $metaTemplate->render();
    }
}
