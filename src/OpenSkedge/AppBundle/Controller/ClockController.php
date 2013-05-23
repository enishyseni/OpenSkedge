<?php

namespace OpenSkedge\AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

use OpenSkedge\AppBundle\Entity\ArchivedClock;
use OpenSkedge\AppBundle\Entity\Clock;
use OpenSkedge\AppBundle\Entity\User;

/**
 * Controller for manipulating time clock entities
 *
 * @category Controller
 * @package  OpenSkedge\AppBundle\Controller
 * @author   Max Fierke <max@maxfierke.com>
 * @license  GNU General Public License, version 3
 * @version  GIT: $Id$
 * @link     https://github.com/maxfierke/OpenSkedge OpenSkedge Github
 */
class ClockController extends Controller
{
    /**
     * Mark the user as clocked in & update the clock in timestamp.
     * If it's a new week from their last clock in, backup their time clock
     * to an ArchivedClock entity.
     *
     * @param Request $request The user's request object
     *
     * @return Symfony\Component\HttpFoundation\Response
     */
    public function clockInAction(Request $request)
    {
        // Ensure the accessing user is authenticated and authorized ROLE_USER
        if (false === $this->get('security.context')->isGranted('ROLE_USER')) {
            throw new AccessDeniedException();
        }

        // Grab a few services.
        $appSettingsService = $this->get('app_settings');
        $dtUtils = $this->get('dt_utils');

        /* If running on Pagoda Box, get the user's IP directly from HTTP_X_FORWARDED_FOR,
         * otherwise, go to Request::getClientIp()
         */
        $clientIp = (isset($_ENV['PAGODABOX']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $request->getClientIp());
        if (!in_array($clientIp, $appSettingsService->getAllowedClockIps())) {
            throw new AccessDeniedException();
        }

        $em = $this->getDoctrine()->getManager();

        $user = $this->getUser();
        $clock = $user->getClock();

        $now = new \DateTime("now");
        $lastClockWeek = $dtUtils->getFirstDayOfWeek($clock->getLastClock(), true);
        $thisWeek = $dtUtils->getFirstDayOfWeek($now, true);

        // If the current user's clock entity is from before the current week, back it up and reset.
        if ($lastClockWeek->getTimestamp() < $thisWeek->getTimestamp()) {
            $archivedClock = $this->_backupClock($user, $clock);
            $clock->resetClock();
            $em->persist($archivedClock);
        }
        $clock->setStatus(true);
        $clock->setLastClock($now);

        $em->persist($clock);

        // Get a list of late shifts from today where the user has not arrived.
        $lateShifts = $em->createQuery('SELECT DISTINCT ls FROM OpenSkedgeBundle:LateShift ls
                                    WHERE (ls.arrivalTime IS NULL AND DATE_DIFF(CURRENT_DATE(), ls.creationTime) = 0
                                    AND ls.user = :uid) ORDER BY ls.creationTime DESC')
                                ->setParameter('uid', $user->getId())
                                ->getResult();
        // Get the time record index for the current time.
        $curIndex = $dtUtils->getIndexFromTime($now);
        // Get the day number from the current day.
        $dayNumber = $now->format("w");

        foreach ($lateShifts as $lateShift) {
            /* If one of the position schedules associated with one today's lateshifts is scheduled for now,
             * they are arriving for the shift and thus arrivalTime should be set to indicate they showed up
             * for the shift.
             */
            if ($lateShift->getSchedule()->getDayOffset($dayNumber, $curIndex) == '1') {
                $lateShift->setArrivalTime($now);
                $em->persist($lateShift);
            }
        }

        $em->flush();

        return $this->redirect($this->generateUrl('dashboard'));
    }

    /**
     * Mark the user as clocked out & update the relevant time records.
     *
     * @param Request $request The user's request object
     *
     * @return Symfony\Component\HttpFoundation\Response
     */
    public function clockOutAction(Request $request)
    {
        // Ensure the accessing user is authenticated and authorized ROLE_USER
        if (false === $this->get('security.context')->isGranted('ROLE_USER')) {
            throw new AccessDeniedException();
        }

        /* If running on Pagoda Box, get the user's IP directly from HTTP_X_FORWARDED_FOR,
         * otherwise, go to Request::getClientIp()
         */
        $clientIp = (isset($_ENV['PAGODABOX']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : $request->getClientIp());
        if (!in_array($clientIp, $this->get('app_settings')->getAllowedClockIps())) {
            throw new AccessDeniedException();
        }

        $em = $this->getDoctrine()->getManager();

        $curTime = new \DateTime("now");
        $yesterday = clone $curTime;
        $yesterday->modify("-1 days");

        $user = $this->getUser();
        $clock = $user->getClock();

        $lastClock = $clock->getLastClock();
        $startTime = clone $lastClock;

        /* TODO: If they're clocking in over two pay periods. (e.g. Sunday night to monday morning)
         * we should update their archivedClock for that week.
         *
         * If the date of lastClock is the previous day, we need to update two timerecords.
         */
        if ($lastClock->format('Y-m-d') === $yesterday->format('Y-m-d')) {
            $prevDayEnd = clone $lastClock;
            $prevDayEnd->setTime(23, 59, 59);
            $getDay = "get".$lastClock->format('D');
            $yesterdayTimerecord = $this->_updateTimeRecord($clock->$getDay(), $lastClock, $prevDayEnd);
            $setDay = "set".$lastClock->format('D');
            $clock->$setDay($yesterdayTimerecord);
            // The the final timerecord will be a continuation of midnight today until the current time.
            $startTime = new \DateTime("midnight today");
        } elseif ($lastClock->format('Y-m-d') < $yesterday->format('Y-m-d')) {
            /* They forgot to clock out over a number of days.
             * set $curTime to 23,59,59 on the last day the clocked in.
             * Not a perfect solution, but it minimizes the damage from not clocking out.
             */
            $curTime = clone $startTime;
            $curTime->setTime(23, 59, 59);
        }

        $getDay = "get".$startTime->format('D');
        $setDay = "set".$startTime->format('D');
        $curTimerecord = $clock->$getDay();
        $timerecord = $this->_updateTimeRecord($curTimerecord, $startTime, $curTime);
        $clock->$setDay($timerecord);
        $clock->setStatus(false);

        $em->persist($clock);
        $em->flush();

        return $this->redirect($this->generateUrl('dashboard'));
    }

    /**
     * Backup a Clock entity to an ArchivedClock entity.
     *
     * @param UserInterface $user  The user of which's clock to manipulate.
     * @param Clock         $clock The user's Clock entity
     *
     * @return ArchivedClock
     */
    private function _backupClock(UserInterface $user, Clock $clock)
    {
        $dtUtils = $this->get('dt_utils');

        $archivedClock = new ArchivedClock();
        $archivedClock->setUser($user);

        for ($i = 0; $i < 7; $i++) {
            $archivedClock->setDay($i, $clock->getDay($i));
        }
        $archivedClock->setWeek($dtUtils->getFirstDayOfWeek($clock->getLastClock()));

        return $archivedClock;
    }

    /**
     * Set 1s in the given timerecord 96-character string from $start to $end
     *
     * @param string    $timerecord 96-character time record string
     * @param \DateTime $start      Start time
     * @param \DateTime $end        End time
     *
     * @return string
     */
    private function _updateTimeRecord($timerecord, \DateTime $start, \DateTime $end)
    {
        // Get a \DateTime for midnight of $start's day.
        $daystart = clone $start;
        $daystart->setTime(0, 0, 0);

        // Time record index of the start time (exclusive)
        $startInd = round((($start->getTimestamp() - $daystart->getTimestamp()) / 60) / 15);
        // Time record index of the end time (inclusive)
        $endInd = round((($end->getTimestamp() - $daystart->getTimestamp()) / 60) / 15);

        for ($i = $startInd; $i < $endInd; $i++) {
            $timerecord[$i] = '1';
        }

        return $timerecord;
    }
}
