<?php

namespace Himedia\QCM\Controllers;

use Himedia\QCM\Obfuscator;
use Himedia\QCM\QuizPaper;
use Himedia\QCM\Tools;
use Silex\Application;
use Silex\ControllerProviderInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class Admin implements ControllerProviderInterface
{
    public function connect (Application $app)
    {
        $oController = $app['controllers_factory'];
        $oController->match('/sessions', 'Himedia\QCM\Controllers\Admin::sessions')->bind('admin_sessions');
        $oController->get('/sessions/{sSessionId}', 'Himedia\QCM\Controllers\Admin::sessionResult');
        $oController->get('/sessions/{sSessionId}/{sThemeId}', 'Himedia\QCM\Controllers\Admin::themeResult');
        return $oController;
    }

    public function sessions (Application $app, Request $request)
    {
        $sDir = $app['config']['Himedia\QCM']['dir']['sessions'];
        $aSessions = $this->getAllSessions($sDir);
        $aObfuscatedSessions = Obfuscator::obfuscateKeys($aSessions, $app['session']->get('seed'));

        return $app['twig']->render('admin-sessions.twig', array(
            'sessions' => $aObfuscatedSessions
        ));
    }

    public function sessionResult (Application $app, Request $request, $sSessionId)
    {
        $sDir = $app['config']['Himedia\QCM']['dir']['sessions'];
        $aSessions = $this->getAllSessions($sDir);
        $sSessionPath = Obfuscator::unobfuscateKey($sSessionId, $aSessions, $app['session']->get('seed'));
        $aSession = $this->loadSession($sSessionPath);

//         return $app['twig']->render('admin-results.twig', array(
//         ));

        $oQuiz = $aSession['quiz'];
        $aQuizStats = $oQuiz->getStats();
        $aAnswers = $aSession['answers'];
        $aTiming = $aSession['timing'];
        $oQuizPaper = new QuizPaper($oQuiz, $aAnswers, $aTiming);
        $aQuizResults = $oQuizPaper->correct();

        $response = $app['twig']->render('stats.twig', array(
            'subtitle' => 'Résultats',
            'firstname' => ucwords($aSession['firstname']),
            'lastname' => ucwords($aSession['lastname']),
            'quiz_stats' => $aQuizStats,
            'quiz_results' => $aQuizResults,
            'session_key' => $sSessionId
        ));

        return new Response($response, 200, $app['cache.defaults']);
    }

    public function themeResult (Application $app, Request $request, $sSessionId, $sThemeId)
    {
        $sDir = $app['config']['Himedia\QCM']['dir']['sessions'];
        $aSessions = $this->getAllSessions($sDir);
        $sSessionPath = Obfuscator::unobfuscateKey($sSessionId, $aSessions, $app['session']->get('seed'));
        $aSession = $this->loadSession($sSessionPath);

        $oQuiz = $aSession['quiz'];
        $aQuizStats = $oQuiz->getStats();
        $aQuestions = $oQuiz->getQuestions();

        $iNbQuestions = $aQuizStats['nb_questions'];

        $aAnswers = $aSession['answers'];
        $aTiming = $aSession['timing'];
        $oQuizPaper = new QuizPaper($oQuiz, $aAnswers, $aTiming);
        $aQuizResults = $oQuizPaper->correct();

        $aThemes = array_keys($aQuizResults['answer_types_by_theme']);
        $sTheme = $aThemes[$sThemeId];
        $aThemeQuestions = $aQuizResults['all_questions_by_theme'][$sTheme];
        $aQuestionsSubject = array();
        $aQuestionsChoices = array();
        foreach (array_keys($aThemeQuestions) as $iQuestionNumber) {
            $aQuestion = $aQuestions[$iQuestionNumber-1];
            $aQuestionsSubject[$iQuestionNumber] = Tools::formatText($aQuestion[1]);
            $aQuestionsChoices[$iQuestionNumber] = Tools::formatQuestionChoices(array_keys($aQuestion[2]));
        }

        $response = $app['twig']->render('admin-theme-result.twig', array(
            'subtitle' => 'Correction par thème',
            'firstname' => ucwords($aSession['firstname']),
            'lastname' => ucwords($aSession['lastname']),
            'theme_questions' => $aThemeQuestions,
            'nb_questions' => $iNbQuestions,
            'questions_subject' => $aQuestionsSubject,
            'questions_choices' => $aQuestionsChoices,
            'questions' => $aQuestions,
            'answers' => $aAnswers,
            'quiz_results' => $aQuizResults,
//             'all_themes' => $aThemes,
            'session_key' => $sSessionId,
            'theme_id' => $sThemeId
        ));
        return new Response($response, 200, $app['cache.defaults']);
    }

    private function getAllSessions ($sDirectory)
    {
        $aSessions = array();
        $oFinder = new Finder();
        $oFinder->files()->in($sDirectory)->name('/\d{8}-\d{6}_[a-z0-9]{32}/')->depth(0)->date('since 30 days ago');
        foreach ($oFinder as $oFile) {
            $sPath = $oFile->getRealpath();
            $aSummary = $this->loadSummaryOfSession($sPath);
            $aSessions[$sPath] = $aSummary;
        }
        krsort($aSessions);
        return $aSessions;
    }

    private function loadSummaryOfSession ($sPath)
    {
        $aSession = $this->loadSession($sPath);
        $oQuizPaper = new QuizPaper($aSession['quiz'], $aSession['answers'], $aSession['timing']);

        $aSummary = $aSession;
        $aSummary['quiz_stats'] = $aSession['quiz']->getStats();
//         unset($aSummary['quiz']['questions']);
        unset($aSummary['quiz']);
        unset($aSummary['answers']);
        unset($aSummary['timing']);
        $aSummary['candidate'] = ucwords($aSummary['firstname']) . ' ' . ucwords($aSummary['lastname']);
        $aSummary['result'] = $oQuizPaper->correct();
        return $aSummary;
    }

    private function loadSession ($sPath)
    {
        $aSession = unserialize(file_get_contents($sPath));
        return $aSession;
    }
}
