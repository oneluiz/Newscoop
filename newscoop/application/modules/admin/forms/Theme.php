<?php 

class Admin_Form_Theme extends Zend_Form
{
    public function init()
    {
        $this->setAttrib( "autocomplete", "off" );
        $this->addElement('text', 'required-version', array
        (
            'label'       => getGS( 'Required Newscoop version' ),
            'description' => getGS( 'or higher' ),
        	'required'    => true,
        ));
        
        $this->addElement( 'text', 'theme-version', array
        (
            'label'    => getGS( 'Theme version' ),
        	'required' => true,
        ));
        $this->setAction('')->setMethod( Zend_Form::METHOD_POST );
    }
}