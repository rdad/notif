<?php
/**
 * Notif_manager : Gére les notifications
 * - action des autres utilisateurs sur mes données
 * - journal de mes likes
 *
 * Utilisé sur la page notification et par l'API notification
 * @package Notification
 * @category Bibliothêque (libraries)
 * 
 */
class Notif_manager {
    
    public function __construct(){
        
        ci()->load->library(array('DataFormat', 'Mobile_push'));
    }
    
    /**
     * Rajoute à l'activité de l'utilisateur les notifications suivantes
     * @param array $notif_list liste des ids
     * @param \Entity\User $user
     */
    public function add_new_notif_visited($notif_list, $user){
        

        $nb = count($notif_list);
            
        if($nb>0){

            $activity = $user->getActivity();

            if(is_null($activity)){

                $activity = new \Entity\UserActivity;
                $user->setActivity($activity);
                ci()->doctrine->em->persist($activity);
                ci()->doctrine->em->flush();
                //$activity->setUser($user);

            }  

            $activity->add_notification_visited($notif_list);
            ci()->doctrine->em->flush();
        }
        
        return $nb;
    }
    
    /**
     * Permet de générer la liste des notifications pour la page notification (colonne de gauche)
     * @param \Doctrine\Common\Collections\ArrayCollection $data Collection d'entité Notification
     * @param DateTime $last_access Date de dernier accés à la page notification (user->last_notification_access)
     * @param $add_delimiter boolean Doit on ajouter le délimiteur "Déjà lues"
     * @return string Html de la liste
     */
    public function get_html_notif_list($data, $last_access, $add_delimiter = false){
     
        $result = '';
        $list   = array();
        $view   = 'notification/widget_notification';
        
        $me         = ci()->authentification->get_active_user();
        $activity   = $me->getActivity();

        
        $like_me_action_list = array(
            \Entity\Notification::COMMENT,
            \Entity\Notification::LIKE,
            \Entity\Notification::SHARE
        );

        foreach($data as $notif){
            
            $n = array(
                'create_at'=>$notif->getCreate_at(),
                'type'=>$notif->getType(),
                'note'=>$notif->getNote(),
                'event'=>(!is_null($notif->getTarget_event())) ? true : false,
                'id'=>$notif->getId(),
                'visited'=>(is_null($activity)) ? false : $activity->is_notification_visited($notif)
            );
            
            // update des messages à la volée quand on a une action de similitude (A AUSSI)
            
            if($notif->getAuthor() != $me && 
                    $notif->getTarget_user() != $me && 
                    in_array($notif->getType(), $like_me_action_list) &&
                    is_null($notif->getTarget_group()) &&
                    is_null($notif->getTarget_event())){
                
                $n['note'] = $this->build_notif_note_like_me($notif);
                $n['note'] = str_replace(ci()->config->item('motospot_url_slug'), base_url(), $n['note']);
            }
            
            $list[] = $n;
            
            /* select read & unread notif
            
            if($notif->getCreate_at()<$last_access){
                $read[]     = $n;
            }else{
                $unread[]   = $n;
            }*/
        }
        
        // --- render
        
        $data = array(
          'type'=>0,
          'data'=>$list
        );
        $result .= ci()->load->view($view, $data, TRUE);
        
        
        /*if($add_delimiter)    $result .= '<div class="sep"><div>Déjà lues</div></div>';
        
        // --- Read
        
        $data = array(
          'type'=>0,
          'data'=>$read,
          'read'=>true
        );
        $result .= ci()->load->view($view, $data, TRUE);*/
        
        return $result;
    }

    public function get_html_notif_list_for_mail($data, $me){
     
        $result = '';
        $unread = array();
        $read   = array();
        $view   = 'mailer/widget_notification';
        $activity = $me->getActivity();
        
        $like_me_action_list = array(
            \Entity\Notification::COMMENT,
            \Entity\Notification::LIKE,
            \Entity\Notification::SHARE
        );

        foreach($data as $notif){

            $n = array(
                'create_at'=>$notif->getCreate_at(),
                'type'=>$notif->getType(),
                'note'=>$notif->getNote(),
                'event'=>(!is_null($notif->getTarget_event())) ? true : false,
                'id'=>$notif->getId(),
                'visited'=>(is_null($activity)) ? false : $activity->is_notification_visited($notif)
            );
            
            // update des messages à la volée quand on a une action de similitude (A AUSSI)
            
            if($notif->getAuthor() != $me && 
                    $notif->getTarget_user() != $me && 
                    in_array($notif->getType(), $like_me_action_list) &&
                    is_null($notif->getTarget_group()) &&
                    is_null($notif->getTarget_event())){
                
                $n['note'] = $this->build_notif_note_like_me($notif);
                $n['note'] = str_replace(ci()->config->item('motospot_url_slug'), base_url(), $n['note']);
            }
            

            $unread[]   = $n;

        }
        
        // --- Unread
        
        $data = array(
          'type'=>0,
          'data'=>$unread
        );
        $result = ci()->load->view($view, $data, TRUE);

        return $result;
    }
    
    /**
     * Permet de générer les listes de like pour la page notification (colonne de droite)
     * @param \Doctrine\Common\Collections\ArrayCollection $data Collection d'entité Notification
     * @return string Html de la liste
     */
    public function get_html_like_list($data){
        
        $liste  = array();
        
        // regroupe par jour
        
        foreach($data as $like){

            $d = $like->getCreate_at()->format('Y-m-d');
            
            if(!isset($liste[$d])){
                $liste[$d] = array();
            }
            
            $like->setNote($this->build_like_note($like));
            $liste[$d][] = $like;
        }
        
        $data = array(
          'type'=>  Entity\Notification::LIKE,
          'data'=>$liste
        );

        return ci()->load->view('notification/widget_notification', $data, TRUE);
    }
    
    /**
     * Renvoie la représentation en badge (bulle rouge) des notifications en cours
     * @return string Html
     */
    public function get_realtime_widget(){

        $nb = ci()->authentification->get_active_user()->getNb_notification();
        return '<a href="'.site_url('notification').'">'.layoutRoundedBadge($nb).'</a>';
    }
    
    /**
     * Effectue une mise à jour des notifications : Les notifications effectuées après user->last_notification_update seront prises en compte
     * Cette action ne sera pas faite si elle est appelée avant un certain délais ( config:notification_time_for_update )
     * Met à jours les données stockées dans l'entité utilisateur (User) :
     * - nb_notification
     * - last_notification_update
     * 
     * @param boolean $force_update Forcer l'update des notifications sans prendre en compte le temps minimum de l'update
     * @return integer Le nouveau nombre de notification
     */
    public function update_notification($force_update = false){
        
        $user   = ci()->authentification->get_active_user();
        $wait   = ci()->config->item('notification_time_for_update');
        
        $nb     = $user->getNb_notification();
        if(is_null($nb))    $nb = 0;
        
        $now    = new \DateTime('now', new \DateTimeZone('Europe/Paris'));
        $now->sub(new DateInterval('PT'.$wait.'S'));
        
        if($user->getLast_notification_update() < $now || is_null($user->getLast_notification_update()) || $force_update == true){
            
            ci()->load->model('notification/query_notification');
            
            $nb = ci()->query_notification->get_nb_notification($user);
            $user->setNb_notification($nb)
                 ->updateLast_notification_update();
            
            ci()->doctrine->em->persist($user);
            ci()->doctrine->em->flush();
        }
        
        return $nb;
    }
    
    /**
     * Calcul la phrase de notification d'après les données de l'entité
     * Utilisé sur la page notification (colonne de gauche)
     * @param \Entity\Notification $notification
     * @return string
     */
    public function build_notif_note($notif){
        
        $author  = '';
        $action  = '';
        $target  = '';
        $final   = '';
        $link    = '';
        
        // auteur

        $user   = $notif->getAuthor();
        //$author = '<a href="'.$user->get_profil_url(true).'">'.ucfirst($user->getPseudonym()).'</a> ';
        $author = '<strong>'.ucfirst($user->getPseudonym()).'</strong> ';

        // action

        switch($notif->getType()){

            // Rdad a aimé votre publication “le temps de (...)”

            case Entity\Notification::LIKE:
                $action = 'a aimé ';
                break;
            
            case Entity\Notification::COMMENT:
                $action = 'a commenté ';
                break;

            case Entity\Notification::PUBLISH_ON_WALL:
                
                $u      = $notif->getTarget_user();
               
                if(!$u->is_a_group()){
                    $action = 'a publié sur votre mur ';
                }else{
                    $g      = '<strong>'.ucfirst($u->getPseudonym()).'</strong> ';
                    $action = 'a publié sur le mur du groupe '.$g.' ';
                }
                
                break;
            
            case Entity\Notification::PUBLISH_ON_EVENT:
                $action = 'a publié sur le mur ';
                break;
            
            case Entity\Notification::SHARE:
                $action = 'a partagé ';
                break;
            
            case Entity\Notification::FRIEND_REQUEST:
                $action = 'vous a demandé en ami';
                $link   = site_url('amis');
                break;
            
            case Entity\Notification::FRIEND_ACCEPT:
                $action = 'a accepté votre demande d’ami';
                $link   = site_url('amis');
                break;
            
            case Entity\Notification::PUBLISH_MESSAGE:
                $action = 'vous a envoyé';
                break;
            
            case Entity\Notification::GROUP_SUBSCRIBE:
                $action = 'a rejoint ';
                break;
  
            case Entity\Notification::IS_NEW_ADMIN:
                $action = 'vous a nommé administrateur ';
                break;
            
            case Entity\Notification::IS_NO_MORE_ADMIN:
                $action = 'vous a supprimé en tant qu’administrateur';
                break;
            
            case Entity\Notification::EVENT_PARTICIPATE:
                
                $e = $notif->getTarget_event();
                $a = $notif->getAuthor();
                $r = $e->get_participation($a)->getStatut();
                $reponse    = array('ne participera pas ','participera ','participera peut être ');
                $action     = $reponse[$r];
                break;
            
            case Entity\Notification::EVENT_REMINDER:
                
                $action = '"'.$notif->getNote().'"';
                break;
            
            case Entity\Notification::JOIN_REQUEST:
                $action = "vous a invité à rejoindre ";
                break;
                

            default:
                $action = '? ('.$notif->getType().')';
                   
        }
        
        // target
        
        if(!is_null($notif->getTarget_post())){
            
                $e = $notif->getTarget_post();
                $prefix  = ($notif->getType() == Entity\Notification::PUBLISH_ON_WALL) ? null : 'votre';
                $target  = $this->get_accuracy_target($e,$prefix);
                $final   = $this->get_text_resume($e->getText());
                $link    = $e->get_publication_url(true);
                
        }else if(!is_null($notif->getTarget_comment())){
            
                $e       = $notif->getTarget_comment();
                $final   = $this->get_text_resume($e->getText());
                $target  = '<strong>votre commentaire</strong>';
                $link    = $e->getPost()->get_publication_url(true);
                
        }else if(!is_null($notif->getTarget_message())){
            
                $e       = $notif->getTarget_message();
                $target  = ' <strong>un message privé</strong>';
                $link    = ci()->config->item('motospot_url_slug').'messages/'.$user->getId().'/'.$user->getUrl_identifier();
                
        }else if(!is_null($notif->getTarget_group())){
            
                $e       = $notif->getTarget_group();
                $p       = ($notif->getType() >= Entity\Notification::IS_NEW_ADMIN) ? 'du' : 'le';
                $p       = ($notif->getType() == Entity\Notification::LIKE ||
                            $notif->getType() == Entity\Notification::JOIN_REQUEST) ? 'le' : $p;
                $target  = $p.' <strong>groupe "'.$e->getPseudonym().'"</strong>';
                $link    = $e->get_profil_url(true);
                
        }else if(!is_null($notif->getTarget_event())){
            
                $e       = $notif->getTarget_event();
                
                switch($notif->getType()){
                    
                    case Entity\Notification::PUBLISH_ON_EVENT:
                    case Entity\Notification::IS_NEW_ADMIN:
                    case Entity\Notification::IS_NO_MORE_ADMIN:
                    case Entity\Notification::LIKE:
                        $p = 'de ';
                        break;
                    
                    case Entity\Notification::LIKE:
                    case Entity\Notification::JOIN_REQUEST:
                        $p = '';
                        break;
                    
                    case Entity\Notification::EVENT_PARTICIPATE:
                        $p = 'à ';
                        break;
                    
                    default:
                        $p = '';
                }
                
                $target  = '<strong>'.$p.'l\'évènement "'.$e->getName().'"</strong>';
                $link    = $e->get_profil_url(true);
                
                
                if($notif->getType() == Entity\Notification::EVENT_REMINDER){
                    $author = '<strong>'.$e->getName().'</strong>: ';
                    $target = '';
                    $link   = site_url('messages/'.$user->getId());
                }
                
        }else{
            $target = '';
        }

        
        // Note

        $result = $author.$action.$target.$final;
        if($link != '') $result = '<a class="notif_link_visited" data-id="'.$notif->getId().'" href="'.$link.'">'.$result."</a>";
        
        
        // Push notification
        
        $target_user = $notif->getTarget_user();
        
        if(!is_null($target_user)){
            ci()->mobile_push->set($target_user, strip_tags($result))->send();
        }
        
        
        return $result;      
    }
    
    /**
     * Renvoie le méssage de notification pour les actions de type similitude:
     * Rdad a aussi partagé la publication de Spooky “bravo les copains”
     */
    public function build_notif_note_like_me($notif){
        
        $author  = '';
        $action  = '';
        $target  = '';
        $final   = '';
        
        $link    = ''; 
        
        // auteur

        $user   = $notif->getAuthor();
        //$author = '<a href="'.$user->get_profil_url(true).'">'.ucfirst($user->getPseudonym()).'</a> a aussi ';
        $author = '<strong>'.ucfirst($user->getPseudonym()).'</strong> a aussi ';
        
        // action

        switch($notif->getType()){

            case Entity\Notification::LIKE:
                $action = 'aimé ';
                break;
            
            case Entity\Notification::COMMENT:
                $action = 'commenté ';
                break;
            
            case Entity\Notification::SHARE:
                $action = 'partagé ';
                break;
                   
        }
        
        // target
        
        $tauthor    = $notif->getTarget_user();
           
        if(is_null($notif->getTarget_post())){
            $entity     = $notif->getTarget_comment();
            $target     = '<strong>le commentaire</strong>';
            $link       = $entity->getPost()->get_publication_url(true); 
        }else{
            $entity     = $notif->getTarget_post();
            $target     = $this->get_accuracy_target($entity);
            $link       = $entity->get_publication_url(true);
        }

        $final      = $this->get_text_resume($entity->getText());
        $target    .= ' de <strong>'.ucfirst($tauthor->getPseudonym()).'</strong> ';
        
        return '<a class="notif_link_visited" data-id="'.$notif->getId().'" href="'.$link.'">'.$author.$action.$target.$final.'</a>';
    }
    
    /**
     * Calcul la phrase de notification des likes d'après les données de l'entité
     * Utilisé sur la page notification (colonne de droite)
     * @param \Entity\Notification $notification
     * @return string
     */
    public function build_like_note($notif){

           $start   = 'Vous avez aimé ';
           $target  = '';
           $user    = '';
           $final   = '';
           
           //Doctrine\Common\Util\Debug::dump($notif);
           //exit();
           
           // Cible
           
           switch($this->get_target_name($notif)){
               
               case 'post':
                   
                   $e       = $notif->getTarget_post();
                   $user    = $e->getUser();
                   $name    = '<a href="'.$user->get_profil_url(true).'">'.ucfirst($user->getPseudonym()).'</a>';
                   $url     = $e->get_publication_url(true);
                   
                   // désignation précise              
                   $target  = $this->get_accuracy_target_for_like($e);
                   break;
               
               case 'comment':
                   
                   // echo $notif->getId().', ';
                   
                   $e       = $notif->getTarget_comment();
                   $user    = $e->getUser();
                   $name    = '<a href="'.$user->get_profil_url(true).'">'.ucfirst($user->getPseudonym()).'</a>';
                   $final   = $this->get_text_resume($e->getText());
                   $target  = '<a href="'.$e->getPost()->get_publication_url(true).'">le commentaire</a>';
                   break;
               
               case 'group':
                   
                   $group   = $notif->getTarget_group();
                   $user    = $group->getGroup_author(); 
                   $name    = '<a href="'.$user->get_profil_url(true).'">'.ucfirst($user->getPseudonym()).'</a>';
                   $target  = 'le groupe <a href="'.$group->get_profil_url(true).'">'.ucfirst($group->getPseudonym()).'</a>';
                   break;
               
               case 'event':
                   
                   $e       = $notif->getTarget_event();
                   $user    = $e->getUser();
                   $name    = '<a href="'.$user->get_profil_url(true).'">'.ucfirst($user->getPseudonym()).'</a>';
                   $target  = 'l\'évènement <a href="'.$e->get_profil_url(true).'">'.ucfirst($e->getName()).'</a>';
                   break;
           }

           // Note
           
           return $start.$target.' de '.$name.$final;
       }
       
       /**
        * Détermine l'Entité cible (post, comment, group, event, message, user) et renvoie son nom
        * @param \Entity\Notification $notification
        * @return string
        */
       public function get_target_name($notification){
           
           $name = '';
           
           switch($notification->getType()){
               
               case Entity\Notification::PUBLISH_ON_EVENT:
               case Entity\Notification::EVENT_PARTICIPATE:
               case Entity\Notification::EVENT_CREATE:
                   $name = 'event';
                   break;
               
               case Entity\Notification::GROUP_SUBSCRIBE:
               case Entity\Notification::GROUP_CREATE:
                   $name = 'group';
                   break;
               
               case Entity\Notification::JOIN:        
                   $name = (is_null($notification->getTarget_group())) ? 'event' : 'group';
                   break;
               
               case Entity\Notification::PUBLISH_MESSAGE:
                   $name = 'message';
                   break;
               
               case Entity\Notification::FRIEND_REQUEST:
                   $name = 'user';
                   break;
               
               case Entity\Notification::LIKE: 
                   
                   if(!is_null($notification->getTarget_post())){
                       $name = 'post';
                   }else if(!is_null($notification->getTarget_comment())){
                       $name = 'comment';
                   }else if(!is_null($notification->getTarget_event())){
                       $name = 'event';
                   }else if(!is_null($notification->getTarget_group())){
                       $name = 'group';
                   }
                   break;
               
               case Entity\Notification::SHARE:
               case Entity\Notification::COMMENT:
               case Entity\Notification::PUBLISH_ON_WALL:
                   $name = 'post';
                   break;
           }
           return $name;
       }
    
       /**
        * Renvoie une représentation html complête de l'entité
        * Cela comprend un lien vers la publication.
        * @param \Entity\Post $post
        * @param string $prefix pronom utilisé à la place de ceux par défaut (pour le posséssif : votre ...)
        * @return string html
        */
       private function get_accuracy_target($post, $prefix = null){
           
           $target = '<strong>';
           
            if(is_null($post->getAlbum()) && is_null($post->getImage())){
                $target  .= (is_null($prefix)) ? 'la' : $prefix;
                $target .= ' publication</strong>';
            } else if(is_null($post->getImage())){
                $target  .= (is_null($prefix)) ? 'l\'' : $prefix;
                $target  .= ' album</strong>';
            } else{
                $target  .= (is_null($prefix)) ? 'la' : $prefix;
                $target  .= ' photo</strong>';
            }

            return $target;
       }
       
       /**
        * Renvoie une représentation html complête de l'entité
        * Cela comprend un lien vers la publication.
        * @param \Entity\Post $post
        * @param string $prefix pronom utilisé à la place de ceux par défaut (pour le posséssif : votre ...)
        * @return string html
        */
       private function get_accuracy_target_for_like($post, $prefix = null){
           
           $target = '<a href="'.$post->get_publication_url(true).'">';
           
            if(is_null($post->getAlbum()) && is_null($post->getImage())){
                $target  .= (is_null($prefix)) ? 'la' : $prefix;
                $target .= ' publication</a>';
            } else if(is_null($post->getImage())){
                $target  .= (is_null($prefix)) ? 'l\'' : $prefix;
                $target  .= ' album</a>';
            } else{
                $target  .= (is_null($prefix)) ? 'la' : $prefix;
                $target  .= ' photo</a>';
            }

            return $target;
       }
       
     /**
     * Préparation des likes pour l'affichage:
     * - Regrouppement des likes par date
      * @param \Doctrine\Common\Collections\ArrayCollection $data Collection d'entités
      * @return array En clé les dates, en valeur un tableau de like
     */
    private function prepare_likes($data){
        
        $liste  = array();
        
        foreach($data as $like){

            $d = $like->getCreate_at()->format('Y-m-d');
            
            if(!isset($liste[$d])){
                $liste[$d] = array();
            }
            
            $liste[$d][] = $like;
        }

        return $liste;
    }
    
    /**
     * Permet de généré une version courte du texte.
     * Renvoie le début du texte avec la bonne saisure et (...)
     * @param type $txt
     * @return string Résultat de l'opération
     */
    private function get_text_resume($txt){
        
        $result     = '';
        $nb_word    = 5;
        $words      = explode(' ', $txt);
        
        if(count($words)<$nb_word){
            $result = $txt;
        }else{
            for($j=0; $j<$nb_word; $j++){
                $result .= $words[$j].' ';
            }
            $result .= '(...)';
        }
        
        if(strlen($result)>0){
            $result = ' "'.$result.'"';
        }
        return $result;
    }
    
}

?>
