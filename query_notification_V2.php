<?php

use Entity\Entity;

/**
 * Query notification : Model des notifications
 * Permet de faire des requêtes sur la table notification
 * v1.2 : filtrage des notifications antérieurs à l'action de l'utilisateur
 *
 * Utilisé sur la page notification et par l'API notification
 * @package Notification
 * @category Modèle (/models)
 * @version 1.2
 */
class Query_notification extends CI_Model
{
    /**
     * @var integer Nombre de notification autorisé par page 
     */
    private $by_page;
    private $prefetch_quantity;
    
    function __construct() {
        
        parent::__construct();       
        $this->by_page              = $this->config->item('notification_nb_by_page');       
        $this->prefetch_quantity    = $this->by_page * 3;
    }
    
    
    public function get_by_target($target_name, $target_id, $type_id, $nb_by_page = 10, $page = 0){
        
        $qb       = $this->doctrine->em->createQueryBuilder();
        
        $qb->from('Entity\Notification', 'n')
                ->select('n')
                ->where($qb->expr()->eq('n.type', ':type'))
                ->andWhere($qb->expr()->eq('n.'.$target_name, ':target_id'))
                ->orderBy('n.create_at', 'DESC')
                ->setParameter('type', $type_id)
                ->setParameter('target_id', $target_id)
                ->setMaxResults($nb_by_page)
                ->setFirstResult($page * $nb_by_page);
     
        //if($page>0)   $qb->setFirstResult($page * $nb_by_page + 1);      
        //$qb->setFirstResult(5);
               
        $query = $qb->getQuery();
        
        $paginator = new Doctrine\ORM\Tools\Pagination\Paginator($query, false);
        //if($page>0) echo $query->getSql();
        //$query->useResultCache(FALSE);
        return $paginator;
        //return $query->getResult(); 
    }

    
    /**
     * Renvoie une Collection d'entité Like
     * @param \Entity\User $user
     * @param integer $page Pagination des résultats (utilisé par l'API social/like_list)
     * @return \Doctrine\Common\Collections\ArrayCollection
     */
    public function get_like($user, $page=0){
        
        $qb       = $this->doctrine->em->createQueryBuilder();
        
        $qb->from('Entity\Notification', 'n')
                ->select('n')
                ->where('n.author = ?1')
                ->andWhere('n.type = ?2')
                /*->andWhere('n.target_group = ?3')
                ->andWhere('n.target_event = ?3')*/
                ->orderBy('n.create_at', 'DESC')
                ->setParameter(1,$user)
                ->setParameter(2, \Entity\Notification::LIKE)
                //->setParameter(3, null)
                ->setMaxResults($this->by_page);
     
        if($page>0)   $qb->setFirstResult( ($page * $this->by_page) + 1 );
               
        $query = $qb->getQuery();
        $query->useResultCache(FALSE);

        return $query->getResult(); 
    }
    
     private function get_base_query($user){
 
        // --- REQUETE B
         
         
        $qb                 = $this->doctrine->em->createQueryBuilder();       
        $my_event_admin_qb  = $this->doctrine->em->createQueryBuilder();
        $my_event_member_qb = $this->doctrine->em->createQueryBuilder();

        $my_like_post_qb    = $this->doctrine->em->createQuery("SELECT p FROM Entity\Post p JOIN p.notifications c WITH c.author = :user AND  c.type = :action_like");
        $my_like_comment_qb = $this->doctrine->em->createQuery("SELECT p1 FROM Entity\Comment p1 JOIN p1.notifications c1 WITH c1.author = :user AND  c1.type = :action_like");
        $my_like_group_qb   = $this->doctrine->em->createQuery("SELECT p4 FROM Entity\User p4 JOIN p4.group_notifications c4 WITH c4.author = :user AND  c4.type = :action_like");
        $my_like_event_qb   = $this->doctrine->em->createQuery("SELECT p5 FROM Entity\Event p5 JOIN p5.notifications c5 WITH c5.author = :user AND  c5.type = :action_like");        
        
        $my_share_post_qb   = $this->doctrine->em->createQuery("SELECT p2 FROM Entity\Post p2 JOIN p2.notifications c2 WITH c2.author = :user AND  c2.type = :action_share");
        $my_share_event_qb  = $this->doctrine->em->createQuery("SELECT p6 FROM Entity\Event p6 JOIN p6.notifications c6 WITH c6.author = :user AND  c6.type = :action_share");
        $my_share_group_qb  = $this->doctrine->em->createQuery("SELECT p7 FROM Entity\User p7 JOIN p7.group_notifications c7 WITH c7.author = :user AND  c7.type = :action_share");
        
        
        $my_comment_post_qb = $this->doctrine->em->createQuery("SELECT p3 FROM Entity\Post p3 JOIN p3.notifications c3 WITH c3.author = :user AND  c3.type = :action_comment");

       // les évènements que l'utilisateur administre

        $my_event_admin_qb->select('e')
                ->from('Entity\Event', 'e')
                ->join('e.admins', 'a1', 'WITH', $qb->expr()->eq('a1.id', ':user_id'));
        
        // les évènements dont j'ai donné une réponse positive (OUI, PEUT ETRE)
        
        $my_event_member_qb->select('e2')
                ->from('Entity\Event', 'e2')
                ->join('e2.participations', 'ep', 'WITH', $qb->expr()->andX(
                        $qb->expr()->eq('ep.user', ':user'),
                        $qb->expr()->orX(
                            $qb->expr()->eq('ep.statut', ':answer_yes'),
                            $qb->expr()->eq('ep.statut', ':answer_maybe')
                        )
                ));

        // les groupes dont l'utilisateur est membre
        
        if(!$user->is_a_group()){
            
            $my_group_member_qb = $this->doctrine->em->createQueryBuilder();
            
            $my_group_member_qb->select('g')
                ->from('Entity\User', 'g')
                ->join('g.group_members', 'gm', 'WITH', $qb->expr()->eq('gm.id', ':user_id'));
        }
        
        // --- FINAL QUERY


        $qb->from('Entity\Notification', 'n')
            //->select('n')
    // je suis la cible
            ->where($qb->expr()->andX(
                        $qb->expr()->eq('n.target_user', ':user'),
                        $qb->expr()->neq('n.author', ':user')
                    ))
    // re-liké post, comment, group, event
            ->orWhere($qb->expr()->andX(
                        $qb->expr()->orX($qb->expr()->in('n.target_post', $my_like_post_qb->getDQL()),
                                        $qb->expr()->in('n.target_comment', $my_like_comment_qb->getDQL()),
                                        $qb->expr()->in('n.target_group', $my_like_group_qb->getDQL()),
                                        $qb->expr()->in('n.target_event', $my_like_event_qb->getDQL())),                        
                        $qb->expr()->eq('n.type', ':action_like'),
                        $qb->expr()->neq('n.author', ':user')
                    ))
    // re-sharé post, group, event
            ->orWhere($qb->expr()->andX(
                        $qb->expr()->orX($qb->expr()->in('n.target_post', $my_share_post_qb->getDQL()),
                                $qb->expr()->in('n.target_group', $my_share_group_qb->getDQL()),
                                $qb->expr()->in('n.target_event', $my_share_event_qb->getDQL())),
                        $qb->expr()->eq('n.type', ':action_share'),
                        $qb->expr()->neq('n.author', ':user')
                    ))
    // re-comment
            ->orWhere($qb->expr()->andX(
                        $qb->expr()->in('n.target_post', $my_comment_post_qb->getDQL()),
                        $qb->expr()->eq('n.type', ':action_comment'),
                        $qb->expr()->neq('n.author', ':user')
                    ))
   
    // évènements admin     
            ->orWhere($qb->expr()->andX(
                        $qb->expr()->in('n.target_event', $my_event_admin_qb->getDQL()),
                        $qb->expr()->neq('n.type', ':notif_join_request'),
                        $qb->expr()->neq('n.author', ':user')    
                    ))
            ->setParameter('notif_join_request', \Entity\Notification::JOIN_REQUEST)
    
    // évènements membre     
            ->orWhere($qb->expr()->andX(
                        $qb->expr()->in('n.target_event', $my_event_member_qb->getDQL()),
                        $qb->expr()->orX(
                                $qb->expr()->eq('n.type', ':action_event_wall'),
                                $qb->expr()->eq('n.type', ':action_event_reminder')
                        ),
                        $qb->expr()->neq('n.author', ':user')    
                    ))
                
    // messages privés
            ->orWhere($qb->expr()->andX(
                        $qb->expr()->eq('n.target_user', ':user'),
                        $qb->expr()->eq('n.type', ':action_message'),
                        $qb->expr()->neq('n.author', ':user')
                    ));
                
    /* dates de souscriptions                
            ->orWhere($qb->expr()->andX(
                        $qb->expr()->eq('n.author', ':user'),
                        $qb->expr()->in('n.type', ':action_subscribe')
                    ));*/
        
    // pour les membres de groupe
        if(!$user->is_a_group()){
            
            $qb->orWhere($qb->expr()->andX(
                        $qb->expr()->in('n.target_user', $my_group_member_qb->getDQL()),
                        $qb->expr()->eq('n.type', ':action_on_wall'),
                        $qb->expr()->neq('n.author', ':user')
            ))
            ->setParameter('action_on_wall', \Entity\Notification::PUBLISH_ON_WALL);
        }

    // pour les groupes
       
        if($user->is_a_group()){
           
            $qb->orWhere($qb->expr()->andX(
                        $qb->expr()->in('n.target_group', ':user'),
                        $qb->expr()->neq('n.type', ':notif_join_request'),
                        $qb->expr()->neq('n.author', ':user')
            ))
            ->setParameter('notif_join_request', \Entity\Notification::JOIN_REQUEST);
        }
        
    // final
        
        $qb->setParameter('action_like', \Entity\Notification::LIKE)
            ->setParameter('action_share', \Entity\Notification::SHARE)
            ->setParameter('action_comment', \Entity\Notification::COMMENT)
            ->setParameter('action_message', \Entity\Notification::PUBLISH_MESSAGE)
            ->setParameter('action_event_wall', \Entity\Notification::PUBLISH_ON_EVENT)
            ->setParameter('action_event_reminder', \Entity\Notification::EVENT_REMINDER)     
            ->setParameter('user', $user)
            ->setParameter('user_id', $user->getId())
            ->setParameter('answer_yes', \Entity\EventJoin::ANSWER_YES)
            ->setParameter('answer_maybe', \Entity\EventJoin::ANSWER_MAYBE)
            /*->setParameter('action_subscribe', array(\Entity\Notification::EVENT_PARTICIPATE,
                                                     \Entity\Notification::GROUP_SUBSCRIBE))*/
            ->orderBy('n.create_at', 'DESC');
        
        return $qb;
    }
    
    /**
     * Récupére les timings de l'utilisateur en cours :
     * - like, share, comment (LSC)
     * - inscription groupe, évènement, amitié (GEA)
     * Par défaut les LSC sont pris en compte sur les 2 derniers mois.
     * @param \Entity\User $user
     * @param Boolean $get_all_time_data Prise en compte des LSC sur 4 mois.
     * @return Array(Assoc)
     */
    public function get_base_user_timing($user, $get_all_time_data = false){

        $monitored = array( \Entity\Notification::GROUP_SUBSCRIBE,
                            \Entity\Notification::EVENT_PARTICIPATE,
                            \Entity\Notification::FRIEND_ACCEPT);
        
        // les like, share et comment sont monitorés sur une période de 2 mois
        
        $monitored_timed = array( \Entity\Notification::COMMENT,
                            \Entity\Notification::LIKE,
                            \Entity\Notification::SHARE);

        // Requête
        
        $nb_month = ($get_all_time_data) ? 4 : 2;
        $to     = new \DateTime('now', new \DateTimeZone('Europe/Paris'));
        $from   = new \DateTime('now', new \DateTimeZone('Europe/Paris'));
        $from->sub(new \DateInterval("P".$nb_month."M"));
        
        $qba = $this->doctrine->em->createQueryBuilder();
        $qba->select('n')
           ->from('Entity\Notification', 'n')
           ->where($qba->expr()->andX(
                    $qba->expr()->eq('n.author', ':user'),
                    $qba->expr()->in('n.type', ':monitored')
            ))
           ->orWhere($qba->expr()->andX(
                    $qba->expr()->eq('n.author', ':user'),
                    $qba->expr()->in('n.type', ':monitored_timed'),
                    $qba->expr()->between('n.create_at', ':from',':to')
            ))
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('user', $user)
            ->setParameter('monitored', $monitored)
            ->setParameter('monitored_timed', $monitored_timed);

        $query  = $qba->getQuery(); 
        $result = $query->getResult();

        $dates = array();

        foreach($result as $n){
            $h = $this->get_identity_hash($n);
            if(!isset($dates[$h]))    $dates[$h] = $n->getId();
        }
        
        return $dates;
    }
    
    /**
     * Renvoie une Collection d'entité Notification représentant les dernières notifications non lues
     * @param \Entity\User $user
     * @return \Doctrine\Common\Collections\ArrayCollection
     */
    public function get_last_notification($user){

        $dates  = $this->get_base_user_timing($user);
        
        $qb     = $this->get_base_query($user);       
        $qb->select('n')
           ->setMaxResults($this->prefetch_quantity);

        $query  = $qb->getQuery(); 
        $result = $query->getResult();
        
        // exit('<pre>'.$qb->getDQL().'</pre>');
        
        //return $result;
        return $this->remove_outdated_data($result, $dates, $user);
    }

    /**
     * Renvoie une Collection d'entité Notification représentant les dernières notifications non lues
     * @param \Entity\User $user
     * @return \Doctrine\Common\Collections\ArrayCollection
     */
    public function get_last_unread_notification($user){

        $dates = $this->get_base_user_timing($user, true);
        
        $qb = $this->get_base_query($user);
        
        $qb->select('n')
           ->andWhere($qb->expr()->gte('n.create_at', ':last_access'))
           ->setParameter('last_access', $user->getLast_notification_access());
        
        $query = $qb->getQuery();      
        $result = $query->getResult();

        return $this->remove_outdated_data($result, $dates, $user);
    }
    
    /**
     * Renvoie une Collection d'entité Notification représentant les dernières notifications déjà lues
     * @param \Entity\User $user
     * @param integer $last_id Plus récente Notification affichée (utilisé par l'API social/notification_list)
     * @param boolean $lt true: Less than, false: Greater than
     * @return \Doctrine\Common\Collections\ArrayCollection
     */
    public function get_last_already_read_notification($user, $last_id=0, $lt = true){

        $dates  = $this->get_base_user_timing($user);
        
        $qb = $this->get_base_query($user);

        $qb->select('n')
           ->setMaxResults($this->prefetch_quantity);
            // $user->getLast_notification_access()
        
        if($last_id>0){
            
            if($lt){
                $qb->andWhere($qb->expr()->lt('n.id', ':last_id'))
                                ->setParameter('last_id', $last_id);
            }else{
                $qb->andWhere($qb->expr()->gte('n.id', ':last_id'))
                            ->setParameter('last_id', $last_id);
            }
        }

        //if($page>0)   $qb->setFirstResult( ($page * $this->by_page) + 1 );
        
        $query = $qb->getQuery();
        
        //die($query->getSQL());
        
        $result = $query->getResult();

        return $this->remove_outdated_data($result, $dates, $user);
    }
    
    /**
     * Renvoie la Collection d'entité Notification de la semaine passée
     * Utilisé par le mail hebdomadaire (bilan des notifications)
     * @param \Entity\User $user
     * @return \Doctrine\Common\Collections\ArrayCollection
     */
    public function get_last_notification_by_week($user){

        $dates  = $this->get_base_user_timing($user);
        $qb     = $this->get_base_query($user);
        
        // weekly
        
        $now            = new \DateTime('now', new \DateTimeZone('Europe/Paris'));
        $sevenDaysAgo   = new \DateTime('now', new \DateTimeZone('Europe/Paris'));
        $sevenDaysAgo->sub(new \DateInterval("P7D"));
        $last_access    = $user->getLast_notification_access();
        
        $from = ( $last_access > $sevenDaysAgo) ? $last_access : $sevenDaysAgo;

        //exit($now->format('d.m.Y').'/'.$sevenDaysAgo->format('d.m.Y'));
        
        $qb->select('n')
           ->andWhere($qb->expr()->between('n.create_at', ':from',':to'))
           ->setParameter('from', $from)
           ->setParameter('to', $now);
        
        $query = $qb->getQuery();
        $result = $query->getResult();

        return $this->remove_outdated_data($result, $dates, $user);
    }
    
    /**
     * Renvoie le nombre de notifications non lues
     * Détermine les notifications non lues grace à la date de dernier acces à la page Notification (user->last_notification_access)
     * La formule de selection des notifications est la suivante:
     * - Toutes les notifications
     * - de tout type (sauf PUBLISH_ON_WALL)
     * - qui ont pour cible
     * - un \Entity\Post ou un \Entity\Comment
     * - dont je suis propriétaire
     * - qui ont été créé après mon dernier accés à la page notification
     * - OU
     * - les notifications dont je suis le recepteur (notification->receiver)
     * - ordonnés par date de création (notification->create_at)
     * @param \Entity\User $user
     * @return integer Nombre de notifications
     */
    public function get_nb_notification($user){
        
        $qb = $this->get_base_query($user);

        $qb->select('COUNT(n.id)') // ***
           ->andWhere($qb->expr()->gt('n.create_at',':last_access')) // ***
           ->setParameter('last_access', $user->getLast_notification_access()); // ***
        
        
        $query = $qb->getQuery();
        return $query->getScalarResult()[0][1];
    }
    
    
    
    // ---------------- POST PROCESS : REMOVE OUTDATED NOTIF -------------------
    
    
    /**
     * Filtre les notifications antérieurs à l'action de l'utilisateur
     * @param \Doctrine\Common\Collections\ArrayCollection $collection
     * @param \Entity\User $user
     * @return \Doctrine\Common\Collections\ArrayCollection
     */
    private function remove_outdated_data($collection, $dates, $user){
        
        //$dates  = array();
        $result = new \Doctrine\Common\Collections\ArrayCollection();

        $dp     = array();
        $total  = array();
        $max    = $this->by_page;
        
        // --- Construction de la nouvelle liste expurgée des notifs anciennes
        
        foreach ($collection as $notif){
        
            $hash               = $this->get_identity_hash($notif);
            $id                 = $notif->getId().'.';
            $type               = $notif->getType();
            $total[$id.$hash]   = $notif->getId(); 
            $outdated           = false;
            
            switch($type){
                
                case \Entity\Notification::COMMENT:
                case \Entity\Notification::LIKE:
                case \Entity\Notification::SHARE:
                    
                    if(isset($dates[$hash]) && $notif->getId()<$dates[$hash])   $outdated = true;
                    break;
                
                case \Entity\Notification::PUBLISH_ON_EVENT:
                    
                    if(!is_null($notif->getTarget_event())){
                        $hg = '5.E'.$notif->getTarget_event()->getId();
                        if(isset($dates[$hg]) && $notif->getId()<$dates[$hg])   $outdated = true;
                    }
                    break;
                
                case \Entity\Notification::PUBLISH_ON_WALL:
                    if(!is_null($notif->getTarget_user())){                
                        $hg = ($notif->getTarget_user()->is_a_group()) ? '6.G' : '20.U';
                        $hg .= $notif->getTarget_user()->getId();
                        if(isset($dates[$hg]) && $notif->getId()<$dates[$hg])   $outdated = true;
                    }
                    break;
            }

            if(!$outdated){
                $result->add($notif);
                $dp[$id.$hash] = $notif->getId(); 
            }
            
            // limit la quantité des notif
            
            if(count($result)>=$max) break;
        }
        
//        echo 'DATES';
//        echo '<pre>'.print_r($dates, true).'</pre>';
//        echo 'TOTAL';
//        echo '<pre>'.print_r($total, true).'</pre>';
//        echo 'RESULT';
//        echo '<pre>'.print_r($dp, true).'</pre>';
//        echo count($result);
        
        return $result;
    }
    
    /**
     * 
     * @param Entity\Notification $notif
     * @return String
     */
    private function get_identity_hash($notif){
        
        // target
            
        $target = '';

        if(!is_null($notif->getTarget_post())){
            $target = 'P'.$notif->getTarget_post()->getId();
        }else if(!is_null($notif->getTarget_comment())){
            $target = 'C'.$notif->getTarget_comment()->getId();
        }else if(!is_null($notif->getTarget_group())){
            $target = 'G'.$notif->getTarget_group()->getId();
        }else if(!is_null($notif->getTarget_event())){
            $target = 'E'.$notif->getTarget_event()->getId();
        }else if(!is_null($notif->getTarget_message())){
            $target = 'M'.$notif->getTarget_message()->getId();
        }else if(!is_null($notif->getTarget_user())){
            $target = 'U'.$notif->getTarget_user()->getId();
        }

        // type
        $type = $notif->getType();
        return $type.'.'.$target; 
    }
    
    /**
     * Filtre les notifications antérieurs à l'action de l'utilisateur
     * @param \Doctrine\Common\Collections\ArrayCollection $collection
     * @param \Entity\User $user
     * @return \Doctrine\Common\Collections\ArrayCollection
     */
    private function remove_outdated_data_OLD($collection, $user){
        
        // --- Détermine les dates d'action de l'utilisateur en cours
        
        $dates  = array();
        $result = new \Doctrine\Common\Collections\ArrayCollection();
        
        $start = array();
        
        foreach ($collection as $notif){
            
            $hash   = $this->get_identity_hash($notif);
            $id     = $notif->getId();
            
            $start[$notif->getAuthor()->getId().'.'.$hash] = $id;
            
            if($notif->getAuthor()->getId() != $user->getId())    continue;

            $dates[$hash] = $id;             
        }
        
        print_r($start);
        
        print_r($dates);
        $dp = array();
        
        // --- Construction de la nouvelle liste expurgée des notifs anciennes
        
        foreach ($collection as $notif){
        
            // user notif
            if($notif->getAuthor()->getId() == $user->getId())    continue;           
            
            $hash = $this->get_identity_hash($notif);
            
            if(isset($dates[$hash])){
                if($notif->getId()>$dates[$hash]){
                    $result->add($notif);
                    $dp[$hash] = $notif->getId();   
                }
            }
        }
        
        print_r($dp);
        
        return $result;
    }
}
